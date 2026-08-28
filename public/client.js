// Demo glue for the php-realtime module, driven by RealtimeClient.
// Mounts against the /realtime front controller on the same origin.
const BASE = "/realtime";
const $ = (id) => document.getElementById(id);

let client = null;

function log(line) {
  const el = $("log");
  el.textContent = line + "\n" + el.textContent;
}

function wire(c) {
  client = c;
  c.on("connect", () => ($("status").textContent = "connected"));
  c.on("disconnect", () => ($("status").textContent = "reconnecting…"));
  c.on("snapshot", (s) => {
    $("version").textContent = s.version ?? c.version;
    if (s.state && s.state.note !== undefined && document.activeElement !== $("note")) {
      $("note").value = s.state.note;
    }
  });
  c.on("presence", (p) => {
    if (p.count !== undefined) $("viewers").textContent = p.count;
    log(`presence · ${p.count} live`);
  });
  c.on("state.patch", (payload, ev) => {
    $("version").textContent = ev.seq;
    if (payload.note !== undefined && document.activeElement !== $("note")) {
      $("note").value = payload.note;
    }
    log(`#${ev.seq} state.patch ${JSON.stringify(payload)}`);
  });
  c.on("*", (ev) => {
    if (ev.type !== "state.patch" && ev.type !== "presence") log(`#${ev.seq} ${ev.type}`);
  });
}

async function create() {
  const name = $("name").value || "Anon";
  const created = await RealtimeClient.createSession(BASE, { state: { note: "" }, meta: { name } });
  $("sessionId").value = created.sessionId;
  const c = new RealtimeClient(BASE);
  c.attach({ sessionId: created.sessionId, token: created.token, snapshot: created.snapshot, userId: created.userId, role: created.role });
  wire(c);
  $("who").textContent = `you: ${created.role}`;
  c.connect();
  $("status").textContent = "hosting";
  log(`created ${created.sessionId}`);
}

async function join() {
  const sessionId = $("sessionId").value.trim();
  if (!sessionId) return alert("Enter a session id");
  const name = $("name").value || "Anon";
  const c = new RealtimeClient(BASE);
  wire(c);
  try {
    await c.join(sessionId, { meta: { name } });
    $("who").textContent = `you: ${c.role}`;
    log(`joined ${sessionId}`);
  } catch (e) {
    alert("Join failed: " + e.message);
  }
}

function publishNote() {
  if (!client) return;
  client.emit("state.patch", { note: $("note").value }).catch((e) => log("emit error: " + e.message));
}

window.addEventListener("DOMContentLoaded", () => {
  $("createBtn").addEventListener("click", () => create().catch((e) => alert(e.message)));
  $("joinBtn").addEventListener("click", () => join());
  $("note").addEventListener("input", publishNote);
});
