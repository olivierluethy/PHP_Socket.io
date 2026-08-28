/**
 * Socket.io-compatible adapter over php-realtime's RealtimeClient, for TuneVote.
 *
 * Goal: keep every component's `.on(event, cb)` / `.emit(event, payload)` /
 * `.disconnect()` working, so migrating off Socket.io is a one-line change at
 * the connection site. Instead of:
 *
 *     const socket = io(SOCKET_SERVER, { query: { sessionId }, auth: { token } });
 *
 * write:
 *
 *     import { connectRealtime } from '.../realtimeClient';
 *     const socket = connectRealtime({ sessionId, userId: user?.id, token });
 *
 * Then `socket.on('queue_updated', reload)`, `socket.emit('song_reaction', { emoji })`
 * and `socket.disconnect()` behave as before. Global/dashboard sockets that used
 * `io(`${API_BASE}/`)` with no session become `connectRealtime({})` (channel 'global').
 *
 * Env: VITE_RT_BASE (e.g. https://api.tunevote.com/realtime).
 */

import { RealtimeClient } from '../../client/realtime-client.mjs';

const RT_BASE = (import.meta.env?.VITE_RT_BASE || `${import.meta.env?.VITE_API_URL || ''}/realtime`).replace(/\/+$/, '');
const GLOBAL_CHANNEL = 'global';

// Client→server Socket.io events that no longer need an explicit message:
// presence is automatic (heartbeats), host join/leave is derived from presence.
const NOOP_EMITS = new Set(['heartbeat', 'join-session-host', 'leave-session-host']);

/**
 * @param {object} p
 * @param {string|number} [p.sessionId]  omit for the global/dashboard channel
 * @param {string|number} [p.userId]     the TuneVote user id (for accurate roles/counts)
 * @param {string} [p.token]             TuneVote JWT/guest token (passed as meta for the server to map)
 * @param {object} [p.meta]              extra participant meta (name, avatar)
 * @param {string} [p.base]              override the realtime mount URL
 */
export function connectRealtime({ sessionId, userId, token, meta = {}, base } = {}) {
  const channel = sessionId != null ? String(sessionId) : GLOBAL_CHANNEL;
  const client = new RealtimeClient(base || RT_BASE);

  // Stable per-tab identity if the app did not supply a user id, so viewer
  // counts are correct even for anonymous/guest tabs.
  let uid = userId != null ? String(userId) : sessionRead(`rt_uid_${channel}`);
  if (!uid) { uid = 'g_' + Math.random().toString(36).slice(2, 10); sessionWrite(`rt_uid_${channel}`, uid); }

  const errorHandlers = new Set();

  client.join(channel, { userId: uid, meta: { ...meta, appToken: token || null } }).catch((e) => {
    for (const h of errorHandlers) { try { h(e); } catch (_) {} }
  });
  client.connect();

  return {
    /** socket.on(event, cb) — cb receives the event payload, as with Socket.io. */
    on(event, cb) {
      if (event === 'connect_error') { errorHandlers.add(cb); client.on('disconnect', () => cb(new Error('disconnected'))); return this; }
      client.on(event, (payload) => cb(payload));
      return this;
    },
    off(event, cb) { client.off(event, cb); return this; },
    /** socket.emit(event, payload) — ephemeral/action events become module actions. */
    emit(event, payload = {}) {
      if (NOOP_EMITS.has(event)) return this;
      client.emit(event, payload).catch((e) => console.error('[realtime emit]', event, e.message));
      return this;
    },
    disconnect() { client.leave().catch(() => client.disconnect()); },
    get connected() { return client._connected; },
    get raw() { return client; },
  };
}

function sessionRead(k) { try { return sessionStorage.getItem(k); } catch (_) { return null; } }
function sessionWrite(k, v) { try { sessionStorage.setItem(k, v); } catch (_) {} }

export default connectRealtime;
