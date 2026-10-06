// Eigenständige Schnellerfassung: als kleines App-Fenster öffnen und ein Tastenkürzel des Betriebssystems darauf legen
const H = { 'X-Kanban': '1', 'Content-Type': 'application/json' };
const $ = s => document.querySelector(s);
const api = async (m, u, b) => { const r = await fetch('/api' + u, { method: m, headers: H, body: b ? JSON.stringify(b) : undefined }); const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(r.status === 401 || r.status === 423 ? 'Bitte zuerst im Hauptfenster anmelden/entsperren.' : j.error); return j; };
async function fill() {
  const boards = await api('GET', '/boards'); $('#board').replaceChildren(...boards.map(b => Object.assign(document.createElement('option'), { value: b.id, textContent: b.name })));
  const last = localStorage.getItem('kb.quickBoard'); if (last && boards.some(b => b.id == last)) $('#board').value = last;
  await cols();
}
async function cols() { const d = await api('GET', '/boards/' + $('#board').value); $('#col').replaceChildren(...d.columns.filter(c => !c.done).map(c => Object.assign(document.createElement('option'), { value: c.id, textContent: c.name }))); try { localStorage.setItem('kb.quickBoard', $('#board').value); } catch {} }
$('#board').onchange = () => cols().catch(e => $('#msg').textContent = e.message);
$('#f').onsubmit = async e => {
  e.preventDefault(); const t = $('#title').value.trim(); if (!t) return;
  try { await api('POST', '/cards', { column_id: +$('#col').value, title: t, fields: $('#due').value ? { due_date: $('#due').value } : {} }); $('#title').value = ''; $('#msg').textContent = '✓ Gespeichert: ' + t; } catch (x) { $('#msg').textContent = 'Fehler: ' + x.message; }
};
addEventListener('keydown', e => { if (e.key === 'Escape') window.close(); });
fill().catch(e => $('#msg').textContent = e.message);
