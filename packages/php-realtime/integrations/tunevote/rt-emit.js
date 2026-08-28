/**
 * TuneVote (Node) → php-realtime fan-out helper.
 *
 * Drop-in replacement for Socket.io emits. Keep all domain logic in the Node
 * REST routes; wherever you currently do
 *
 *     getIO().to(sessionId).emit('queue_updated', {});
 *     getIO().emit('participant_count_update', { sessionId, count });   // global
 *
 * call instead
 *
 *     rt.emit(sessionId, 'queue_updated', {});
 *     rt.emitGlobal('participant_count_update', { sessionId, count });
 *
 * The PHP module persists the event, advances the session version, and fans it
 * out to clients over long-poll/SSE. Requires Node 18+ (global fetch).
 *
 * Env:
 *   RT_BASE_URL         e.g. http://127.0.0.1:8090/realtime   (php-realtime on the VPS)
 *   RT_SERVICE_SECRET   shared secret, must equal the module's RT_SERVICE_SECRET
 *   RT_GLOBAL_CHANNEL   session id used for dashboard/global events (default 'global')
 */

'use strict';

const RT_BASE_URL = (process.env.RT_BASE_URL || 'http://127.0.0.1:8090/realtime').replace(/\/+$/, '');
const RT_SERVICE_SECRET = process.env.RT_SERVICE_SECRET || '';
const RT_GLOBAL_CHANNEL = process.env.RT_GLOBAL_CHANNEL || 'global';

/**
 * Append + broadcast an event to a session channel.
 * @param {string|number} sessionId
 * @param {string} type       the event name (same strings you emit via Socket.io today)
 * @param {object} [payload]
 * @param {object|null} [statePatch]  shallow-merged into the session's shared state
 */
async function emit(sessionId, type, payload = {}, statePatch = null) {
  const id = encodeURIComponent(String(sessionId));
  try {
    const res = await fetch(`${RT_BASE_URL}/sessions/${id}/emit`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Realtime-Service': RT_SERVICE_SECRET },
      body: JSON.stringify({ type, payload, statePatch }),
    });
    if (!res.ok) console.error('[rt.emit]', type, 'HTTP', res.status);
    return res.ok;
  } catch (e) {
    console.error('[rt.emit]', type, e.message);
    return false;
  }
}

/** Emit to the shared global/dashboard channel (replaces getIO().emit(...)). */
function emitGlobal(type, payload = {}) {
  return emit(RT_GLOBAL_CHANNEL, type, payload);
}

/**
 * Ensure a realtime session exists for a TuneVote session id (idempotent).
 * Call this when a session is created/started. Ignores the "already exists" 409.
 * @param {string|number} sessionId
 * @param {string|number|null} ownerId
 * @param {object} [state]
 */
async function ensureSession(sessionId, ownerId = null, state = {}) {
  try {
    const res = await fetch(`${RT_BASE_URL}/sessions`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Realtime-Service': RT_SERVICE_SECRET },
      body: JSON.stringify({ sessionId: String(sessionId), ownerId: ownerId != null ? String(ownerId) : null, state }),
    });
    return res.ok || res.status === 409;
  } catch (e) {
    console.error('[rt.ensureSession]', e.message);
    return false;
  }
}

module.exports = { emit, emitGlobal, ensureSession, RT_BASE_URL, RT_GLOBAL_CHANNEL };
