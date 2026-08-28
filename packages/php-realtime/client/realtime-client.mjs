/*!
 * ESM shim for bundlers (Vite/webpack) and browsers.
 * Single source of truth is ./realtime-client.js (UMD); this re-exports it.
 *
 *   import { RealtimeClient } from './realtime-client.mjs';
 */
import './realtime-client.js';

const RealtimeClient = (typeof globalThis !== 'undefined' ? globalThis.RealtimeClient : undefined);

export { RealtimeClient };
export default RealtimeClient;
