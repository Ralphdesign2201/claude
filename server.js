// Lokales Kanban – Server ohne Abhängigkeiten (Node 22.13+, eingebautes SQLite)
const [maj, min] = process.versions.node.split('.').map(Number);
if (maj < 22 || (maj === 22 && min < 13)) { console.error(`Node.js ${process.version} ist zu alt. Bitte Node.js 22.13 oder neuer installieren (https://nodejs.org).`); process.exit(1); }
process.removeAllListeners('warning'); process.on('warning', w => { if (w.name !== 'ExperimentalWarning') console.warn(w); });
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const S = require('./server/store');
const R = require('./server/runtime');
const C = require('./server/crypto');
const { routes, HttpError } = require('./server/router');
require('./server/core'); require('./server/stats'); require('./server/io'); require('./server/admin');

const PORT = Number(process.env.PORT || 4545);
const PUBLIC = path.join(__dirname, 'public');
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml', '.json': 'application/json', '.webmanifest': 'application/manifest+json' };
const CSP = "default-src 'self'; connect-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; frame-src 'self'; object-src 'self'; base-uri 'none'; form-action 'self'";

try { S.start(); S.acquireLock(PORT); } catch (e) { console.error('\n' + e.message + '\n'); process.exit(1); }
if (S.db) { require('./server/core').seed(); S.dailyBackup(); }
setInterval(() => { try { S.dailyBackup(); } catch (e) { console.error('Sicherung fehlgeschlagen:', e.message); } }, 3600e3).unref();

// ----- Sitzungen -----
const parseCookies = h => Object.fromEntries((h || '').split(/;\s*/).filter(Boolean).map(p => { const i = p.indexOf('='); return [p.slice(0, i), decodeURIComponent(p.slice(i + 1))]; }));
function userOf(req) {
  const t = parseCookies(req.headers.cookie).kb, s = t && R.sessions.get(t);
  if (!s || S.isLocked()) return null;
  return S.get('SELECT * FROM users WHERE id=?', s.id) || null;
}

// ----- Live-Aktualisierung -----
const clients = new Set();
const broadcast = (src) => { for (const c of clients) c.write(`data: ${JSON.stringify({ t: 'change', src })}\n\n`); };
setInterval(() => { for (const c of clients) c.write(': ping\n\n'); }, 25000).unref();

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const headers = (type, extra = {}) => ({ 'Content-Type': type, 'Cache-Control': 'no-store', 'Content-Security-Policy': CSP, 'X-Content-Type-Options': 'nosniff', 'Referrer-Policy': 'no-referrer', 'X-Frame-Options': 'SAMEORIGIN', ...extra });
  const send = (code, body, type = 'application/json; charset=utf-8', extra) => { res.writeHead(code, headers(type, extra)); res.end(typeof body === 'string' || Buffer.isBuffer(body) ? body : JSON.stringify(body)); };
  const lan = !S.isLocked() && S.getSetting('lan', false);

  if (!url.pathname.startsWith('/api/')) {
    let p = decodeURIComponent(url.pathname); if (p === '/') p = '/index.html';
    const file = path.join(PUBLIC, p);
    if (!file.startsWith(PUBLIC + path.sep) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) return send(404, 'Nicht gefunden', 'text/plain; charset=utf-8');
    return send(200, fs.readFileSync(file), TYPES[path.extname(file)] || 'application/octet-stream');
  }
  // Schutz gegen fremde Webseiten: lokal nur localhost-Host, immer Spezial-Header bei Schreibzugriffen
  if (!lan && !/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/.test(req.headers.host || '')) return send(403, { error: 'verboten' });
  if (req.method !== 'GET' && req.headers['x-kanban'] !== '1') return send(403, { error: 'verboten' });

  const chunks = []; let size = 0;
  req.on('data', d => { size += d.length; if (size > 250e6) { req.destroy(); return; } chunks.push(d); });
  req.on('end', () => {
    const raw = Buffer.concat(chunks);
    const found = []; let m, route;
    for (const r of routes) { if (r.method !== req.method) continue; m = url.pathname.match(r.re); if (m) { route = r; break; } }
    if (url.pathname === '/api/events' && req.method === 'GET') {
      if (S.isLocked() || (S.get('SELECT COUNT(*) n FROM users').n > 0 && !userOf(req))) return send(401, { error: 'Anmeldung nötig' });
      res.writeHead(200, { 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-store', Connection: 'keep-alive' }); res.write(': ok\n\n');
      clients.add(res); res.on('close', () => clients.delete(res)); return;
    }
    if (!route) return send(404, { error: 'nicht gefunden' });
    if (S.isLocked() && !route.opts.public) return send(423, { error: 'Gesperrt', locked: true });
    let user_row = null;
    if (!S.isLocked()) {
      user_row = userOf(req);
      if (!route.opts.public && S.get('SELECT COUNT(*) n FROM users').n > 0 && !user_row) return send(401, { error: 'Anmeldung nötig', login: true });
    }
    const ctx = {
      req, res, m, q: url.searchParams, raw, user_row,
      user: user_row?.name || (S.isLocked() ? '' : S.getSetting('display_name', 'Ich')),
      body: {},
      sendRaw: (buf, type, extra) => { ctx.sent = true; send(200, buf, type, extra); },
      setSession: u => { const t = C.randomToken(); R.sessions.set(t, { id: u.id }); ctx.cookie = `kb=${t}; HttpOnly; SameSite=Strict; Path=/`; },
      clearSession: () => { const t = parseCookies(req.headers.cookie).kb; R.sessions.delete(t); ctx.cookie = 'kb=; Max-Age=0; Path=/'; },
    };
    if (!route.opts.raw && raw.length) { try { ctx.body = JSON.parse(raw.toString('utf8')); } catch { return send(400, { error: 'Ungültiges JSON' }); } }
    try {
      const out = route.fn(ctx);
      if (ctx.sent) return;
      if (req.method !== 'GET' && !route.opts.public) { S.markDirty(); broadcast(req.headers['x-client']); }
      return send(200, out === undefined ? {} : out, undefined, ctx.cookie ? { 'Set-Cookie': ctx.cookie } : undefined);
    } catch (e) {
      if (!(e instanceof HttpError)) console.error(e);
      return send(e instanceof HttpError ? e.code : 500, { error: e.message });
    }
  });
});

// ----- Netzwerk: standardmäßig nur dieser Rechner -----
let bound = { host: '127.0.0.1', port: PORT };
function listen() {
  const host = !S.isLocked() && S.getSetting('lan', false) && S.get('SELECT COUNT(*) n FROM users').n > 0 ? '0.0.0.0' : '127.0.0.1';
  bound = { host, port: PORT };
  server.listen(PORT, host, () => console.log(`Lokales Kanban läuft: http://localhost:${PORT}${host === '0.0.0.0' ? '  (im Heimnetz freigegeben)' : ''}\nDaten: ${S.DATA}${S.isLocked() ? '\nDatei ist verschlüsselt – im Browser entsperren.' : ''}`));
}
server.on('error', e => { console.error(e.code === 'EADDRINUSE' ? `Port ${PORT} ist belegt (läuft das Programm schon?). Mit PORT=… anderen Port wählen.` : e.message); shutdown(1); });
R.bind = () => bound;
R.relisten = () => { for (const c of clients) c.end(); clients.clear(); server.close(() => listen()); server.closeAllConnections(); };
R.afterUnlock = () => { try { require('./server/core').seed(); S.dailyBackup(); } catch {} if (S.getSetting('lan', false)) setTimeout(() => R.relisten(), 400); };
listen();

function shutdown(code = 0) { try { S.closeAll(); } catch {} S.releaseLock(); process.exit(code); }
for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) process.on(sig, () => shutdown(0));
