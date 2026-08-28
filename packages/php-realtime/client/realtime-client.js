/*!
 * RealtimeClient — browser client for the PHP-native realtime module.
 *
 * Socket.io-like ergonomics so a frontend migration is mechanical:
 *   socket.on('queue_updated', fn)   ->  client.on('queue_updated', fn)
 *   socket.emit('vote', payload)     ->  client.emit('vote.cast', payload)
 *
 * Transport auto-negotiates SSE (Tier B) then falls back to long/short polling
 * (Tier A). Presence heartbeats, snapshot-on-gap resync and reconnect-with-
 * backoff are built in. Framework-agnostic: loads as a <script> (window.
 * RealtimeClient), CommonJS, or via the .mjs shim for bundlers.
 *
 * Usage:
 *   const client = new RealtimeClient('https://api.example.com/realtime');
 *   await client.join(sessionId, { meta: { name: 'Ada' } });   // or client.attach({...})
 *   client.on('snapshot', s => render(s.state));
 *   client.on('presence', p => setViewerCount(p.count));
 *   client.on('queue_updated', () => reloadQueue());
 *   await client.emit('queue.add', { videoId });
 */
(function (root, factory) {
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (root) root.RealtimeClient = api.RealtimeClient;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  class RealtimeClient {
    /**
     * @param {string} baseUrl  Mount point, e.g. https://host/realtime
     * @param {object} [opts]   { transport:'auto'|'sse'|'poll', heartbeatMs, maxBackoffMs }
     */
    constructor(baseUrl, opts = {}) {
      this.baseUrl = String(baseUrl).replace(/\/+$/, '');
      this.opts = opts;
      this.transportPref = opts.transport || 'auto';
      this.heartbeatMs = opts.heartbeatMs || 10000;
      this.maxBackoffMs = opts.maxBackoffMs || 30000;

      this.sessionId = null;
      this.token = null;
      this.version = 0;
      this.stateCache = {};
      this.role = null;
      this.userId = null;

      this._listeners = new Map(); // type -> Set<fn>
      this._running = false;
      this._es = null;
      this._hbTimer = null;
      this._backoff = 1000;
      this._connected = false;
      this._shortPoll = false;
    }

    // ---- Session bootstrap ------------------------------------------------

    /** Create a new session (returns owner token + snapshot). */
    static async createSession(baseUrl, body = {}) {
      const res = await fetch(String(baseUrl).replace(/\/+$/, '') + '/sessions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      if (!res.ok) throw new Error('createSession failed: ' + res.status);
      return res.json();
    }

    /** Join a session; stores token + snapshot and starts receiving. */
    async join(sessionId, body = {}) {
      const res = await fetch(this.baseUrl + '/sessions/' + encodeURIComponent(sessionId) + '/join', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      if (!res.ok) throw new Error('join failed: ' + res.status);
      const data = await res.json();
      this.attach({ sessionId, token: data.token, snapshot: data.snapshot, userId: data.userId, role: data.role });
      this.connect();
      return data.snapshot;
    }

    /** Attach to an already-known session/token (e.g. issued by your backend). */
    attach({ sessionId, token, snapshot, userId, role }) {
      this.sessionId = sessionId;
      this.token = token;
      if (userId) this.userId = userId;
      if (role) this.role = role;
      if (snapshot) this._applySnapshot(snapshot);
      return this;
    }

    // ---- Pub/sub ----------------------------------------------------------

    on(type, fn) {
      if (!this._listeners.has(type)) this._listeners.set(type, new Set());
      this._listeners.get(type).add(fn);
      return this;
    }

    off(type, fn) {
      const set = this._listeners.get(type);
      if (set) set.delete(fn);
      return this;
    }

    _dispatch(type, ...args) {
      const set = this._listeners.get(type);
      if (set) for (const fn of set) { try { fn(...args); } catch (e) { console.error('[realtime]', type, e); } }
    }

    // ---- Actions ----------------------------------------------------------

    /** Submit a mutation. Optionally pass baseVersion for an optimistic guard. */
    async emit(type, payload = {}, opts = {}) {
      if (!this.sessionId || !this.token) throw new Error('not joined');
      const res = await fetch(this.baseUrl + '/sessions/' + encodeURIComponent(this.sessionId) + '/actions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + this.token },
        body: JSON.stringify({ type, payload, baseVersion: opts.baseVersion ?? null }),
      });
      if (res.status === 409) { const b = await res.json().catch(() => ({})); const e = new Error('version conflict'); e.conflict = true; e.body = b; throw e; }
      if (!res.ok) throw new Error('action failed: ' + res.status);
      return res.json();
    }

    async leave() {
      this.disconnect();
      if (!this.sessionId || !this.token) return;
      try {
        await fetch(this.baseUrl + '/sessions/' + encodeURIComponent(this.sessionId) + '/leave', {
          method: 'POST', headers: { Authorization: 'Bearer ' + this.token }, keepalive: true,
        });
      } catch (_) { /* best effort */ }
    }

    // ---- Connection lifecycle --------------------------------------------

    connect() {
      if (this._running) return;
      this._running = true;
      this._startHeartbeat();
      const canSse = this.transportPref !== 'poll' && typeof EventSource !== 'undefined';
      if (canSse && this.transportPref !== 'poll') this._openSse();
      else this._pollLoop();
    }

    disconnect() {
      this._running = false;
      if (this._es) { this._es.close(); this._es = null; }
      if (this._hbTimer) { clearInterval(this._hbTimer); this._hbTimer = null; }
      this._setConnected(false);
    }

    get state() { return this.stateCache; }

    // ---- SSE (Tier B) -----------------------------------------------------

    _openSse() {
      const url = this.baseUrl + '/sessions/' + encodeURIComponent(this.sessionId) +
        '/stream?since=' + this.version + '&token=' + encodeURIComponent(this.token);
      let es;
      try { es = new EventSource(url); } catch (_) { return this._pollLoop(); }
      this._es = es;

      es.addEventListener('open', () => { this._setConnected(true); this._backoff = 1000; });
      es.addEventListener('snapshot', (e) => this._applySnapshot(JSON.parse(e.data)));
      es.addEventListener('message', (e) => this._handleEvent(JSON.parse(e.data)));
      es.addEventListener('reconnect', () => { es.close(); if (this._running) this._openSse(); });
      es.addEventListener('error', () => {
        es.close(); this._es = null; this._setConnected(false);
        if (!this._running) return;
        // Fall back to polling after repeated SSE failure.
        if (this.transportPref === 'sse') this._retry(() => this._openSse());
        else this._retry(() => this._pollLoop());
      });
    }

    // ---- Polling (Tier A) -------------------------------------------------

    async _pollLoop() {
      if (this._es) return; // SSE won
      while (this._running) {
        const started = Date.now();
        let data;
        try {
          const res = await fetch(
            this.baseUrl + '/sessions/' + encodeURIComponent(this.sessionId) + '/sync?since=' + this.version,
            { headers: { Authorization: 'Bearer ' + this.token } }
          );
          if (!res.ok) throw new Error('sync ' + res.status);
          data = await res.json();
          this._setConnected(true);
          this._backoff = 1000;
        } catch (_) {
          this._setConnected(false);
          await this._sleep(this._nextBackoff());
          continue;
        }

        if (data.kind === 'snapshot') {
          this._applySnapshot(data.snapshot);
        } else {
          for (const ev of data.events || []) this._handleEvent(ev);
          if (typeof data.cursor === 'number') this.version = Math.max(this.version, data.cursor);
        }

        // Adapt: if the server returned an empty batch quickly, the host is not
        // holding long-polls — switch to gentle short-polling.
        const elapsed = Date.now() - started;
        const empty = data.kind !== 'snapshot' && (data.events || []).length === 0;
        if (empty && elapsed < 2000) { this._shortPoll = true; await this._sleep(1500); }
        else if (empty) { this._shortPoll = false; }
      }
    }

    // ---- Shared handling --------------------------------------------------

    _handleEvent(ev) {
      if (!ev || typeof ev.seq !== 'number') return;
      if (ev.seq <= this.version) return; // dedupe
      this.version = ev.seq;
      const type = ev.type;
      const payload = ev.payload || {};
      if (type === 'presence') this._dispatch('presence', payload, ev);
      this._dispatch(type, payload, ev); // per-type listeners
      this._dispatch('*', ev);           // wildcard
    }

    _applySnapshot(snapshot) {
      if (!snapshot) return;
      this.stateCache = snapshot.state || {};
      if (typeof snapshot.version === 'number') this.version = snapshot.version;
      this._dispatch('snapshot', snapshot);
      if (snapshot.viewerCount !== undefined || snapshot.participants) {
        this._dispatch('presence', { count: snapshot.viewerCount, participants: snapshot.participants }, null);
      }
    }

    _startHeartbeat() {
      if (this._hbTimer) clearInterval(this._hbTimer);
      const beat = async () => {
        if (!this.sessionId || !this.token) return;
        try {
          const res = await fetch(
            this.baseUrl + '/sessions/' + encodeURIComponent(this.sessionId) + '/heartbeat',
            { method: 'POST', headers: { Authorization: 'Bearer ' + this.token } }
          );
          if (res.ok) { const d = await res.json(); if (d.nextHeartbeatMs) this.heartbeatMs = d.nextHeartbeatMs; }
        } catch (_) { /* ignore; next beat retries */ }
      };
      beat();
      this._hbTimer = setInterval(beat, this.heartbeatMs);
    }

    _setConnected(v) {
      if (v === this._connected) return;
      this._connected = v;
      this._dispatch(v ? 'connect' : 'disconnect');
    }

    _retry(fn) { this._sleep(this._nextBackoff()).then(() => { if (this._running) fn(); }); }
    _nextBackoff() { const b = this._backoff; this._backoff = Math.min(this._backoff * 2, this.maxBackoffMs); return b; }
    _sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }
  }

  return { RealtimeClient };
});
