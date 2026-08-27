// Minimal client for the PHP long-poll session backend.
// API base: assumes the app is served with /api reachable from the page.
const API = "../api";

async function post(path, body) {
  const r = await fetch(`${API}/${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  return r.json();
}

let session = { id: null, pid: null, cursor: 0 };

async function createSession(name) {
  const res = await post("create.php", { name: "Demo session", state: { note: "" } });
  session.id = res.sessionId;
  document.getElementById("sessionId").value = res.sessionId;
  await join(res.sessionId, name);
}

async function join(sessionId, name) {
  const res = await post("join.php", { sessionId, name });
  if (res.error) return alert(res.error);
  session.id = sessionId;
  session.pid = res.participantId;
  session.cursor = res.cursor;
  render(res.snapshot, res.participants);
  longPoll();
}

async function longPoll() {
  while (session.id) {
    let res;
    try {
      res = await fetch(
        `${API}/poll.php?sessionId=${session.id}&participantId=${session.pid}&since=${session.cursor}`
      ).then((r) => r.json());
    } catch (e) {
      await new Promise((r) => setTimeout(r, 1000));
      continue;
    }
    session.cursor = res.cursor;
    (res.events || []).forEach(applyEvent);
  }
}

function applyEvent(ev) {
  const log = document.getElementById("log");
  log.textContent = `#${ev.seq} ${ev.type} from ${ev.from}: ${JSON.stringify(ev.data)}\n` + log.textContent;
  if (ev.type === "state.patch" && ev.data && ev.data.note !== undefined) {
    const note = document.getElementById("note");
    if (document.activeElement !== note) note.value = ev.data.note;
  }
}

function render(snapshot, participants) {
  if (snapshot && snapshot.note !== undefined) {
    document.getElementById("note").value = snapshot.note;
  }
  document.getElementById("participants").textContent =
    "Participants: " + Object.values(participants || {}).map((p) => p.name).join(", ");
}

function publishNote() {
  if (!session.id) return;
  post("publish.php", {
    sessionId: session.id,
    participantId: session.pid,
    type: "state.patch",
    data: { note: document.getElementById("note").value },
  });
}

window.addEventListener("DOMContentLoaded", () => {
  document.getElementById("createBtn").addEventListener("click", () =>
    createSession(document.getElementById("name").value || "Anon")
  );
  document.getElementById("joinBtn").addEventListener("click", () =>
    join(document.getElementById("sessionId").value, document.getElementById("name").value || "Anon")
  );
  document.getElementById("note").addEventListener("input", publishNote);
});
