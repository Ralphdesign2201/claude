// Anmeldung, Einstellungen, Benutzer, Sicherheit, Sicherungen
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const S = require('./store');
const C = require('./crypto');
const R = require('./runtime');
const { route, bad } = require('./router');
const { all, get, run } = S;
const { str } = require('./core');

const DEFAULTS = { backup_keep: 7, backup_dir2: '', crm_url: '', display_name: 'Ich', lan: false };
const settings = () => Object.fromEntries(Object.entries(DEFAULTS).map(([k, d]) => [k, S.getSetting(k, d)]));
const userCount = () => get('SELECT COUNT(*) n FROM users').n;
const isAdmin = c => userCount() === 0 || c.user_row?.role === 'admin';

const sessionInfo = c => ({
  locked: S.isLocked(), encrypted: S.isEncrypted(),
  auth_required: !S.isLocked() && userCount() > 0,
  user: c.user_row ? { id: c.user_row.id, name: c.user_row.name, role: c.user_row.role } : null,
  display_name: S.isLocked() ? 'Ich' : S.getSetting('display_name', 'Ich'),
});
route('GET', '/api/session', c => sessionInfo(c), { public: true });
route('POST', '/api/unlock', c => {
  const ip = c.req.socket.remoteAddress, f = R.fails.get(ip) || { n: 0, t: 0 };
  if (f.n >= 5 && Date.now() - f.t < 30000) bad('Zu viele Versuche. Bitte kurz warten.', 429);
  if (!S.unlock(String(c.body.password || ''))) { R.fails.set(ip, { n: f.n + 1, t: Date.now() }); bad('Passwort falsch', 401); }
  R.fails.delete(ip); R.afterUnlock(); return { ok: true };
}, { public: true });
route('POST', '/api/login', c => {
  const ip = c.req.socket.remoteAddress, f = R.fails.get(ip) || { n: 0, t: 0 };
  if (f.n >= 5 && Date.now() - f.t < 30000) bad('Zu viele Versuche. Bitte kurz warten.', 429);
  const u = get('SELECT * FROM users WHERE lower(name)=lower(?)', String(c.body.name || ''));
  if (!u || !C.verifyPw(c.body.password || '', u.pass)) { R.fails.set(ip, { n: f.n + 1, t: Date.now() }); bad('Name oder Passwort falsch', 401); }
  R.fails.delete(ip); c.setSession(u); return { ok: true };
}, { public: true });
route('POST', '/api/logout', c => { c.clearSession(); return {}; }, { public: true });

route('GET', '/api/settings', () => settings());
route('PUT', '/api/settings', c => {
  if (!isAdmin(c)) bad('Nur Administratoren', 403);
  const b = c.body, old = settings();
  if ('backup_keep' in b) S.setSetting('backup_keep', Math.max(1, Math.min(365, parseInt(b.backup_keep, 10) || 7)));
  if ('backup_dir2' in b) {
    const d = str(b.backup_dir2, 500).trim();
    if (d) { try { fs.mkdirSync(d, { recursive: true }); fs.accessSync(d, fs.constants.W_OK); } catch (e) { bad('Zweiter Sicherungsordner nicht beschreibbar: ' + e.message); } }
    S.setSetting('backup_dir2', d);
  }
  if ('crm_url' in b) S.setSetting('crm_url', str(b.crm_url, 500).trim());
  if ('display_name' in b) S.setSetting('display_name', str(b.display_name, 60).trim() || 'Ich');
  if ('lan' in b && !!b.lan !== !!old.lan) {
    if (b.lan && userCount() === 0) bad('Für die Netzwerk-Freigabe zuerst unter „Sicherheit“ mindestens einen Benutzer mit Passwort anlegen.');
    S.setSetting('lan', !!b.lan); setTimeout(() => R.relisten(), 400);
  }
  return settings();
});

route('GET', '/api/info', () => {
  const b = R.bind(), nets = Object.values(os.networkInterfaces()).flat().filter(n => n && n.family === 'IPv4' && !n.internal).map(n => n.address);
  return { data_dir: S.DATA, db_file: S.isEncrypted() ? path.join(S.DATA, 'kanban.db.enc') : S.PLAIN, files_dir: S.FILES, backups_dir: S.BACKUPS, bind: b.host, port: b.port,
    urls: b.host === '0.0.0.0' ? nets.map(a => `http://${a}:${b.port}`) : [`http://localhost:${b.port}`], node: process.version, encrypted: S.isEncrypted(),
    csp: "default-src 'self'; connect-src 'self'", telemetry: 'keine', outgoing: 'keine',
    users: userCount(), host: os.hostname() };
});

// ----- Benutzer -----
route('GET', '/api/users', () => all('SELECT id,name,role FROM users ORDER BY name'));
route('POST', '/api/users', c => {
  const first = userCount() === 0;
  if (!first && !isAdmin(c)) bad('Nur Administratoren', 403);
  const name = str(c.body.name, 60).trim(), pw = String(c.body.password || '');
  if (!name) bad('Name fehlt'); if (pw.length < 6) bad('Passwort mindestens 6 Zeichen');
  if (get('SELECT 1 x FROM users WHERE lower(name)=lower(?)', name)) bad('Name schon vergeben');
  const id = Number(run('INSERT INTO users (name,pass,role) VALUES (?,?,?)', name, C.hashPw(pw), first || c.body.role === 'admin' ? 'admin' : 'user').lastInsertRowid);
  if (first) c.setSession(get('SELECT * FROM users WHERE id=?', id));
  return { id };
});
route('PATCH', '/api/users/(\\d+)', c => {
  const id = +c.m[1]; if (!isAdmin(c) && c.user_row?.id !== id) bad('Nicht erlaubt', 403);
  if (c.body.password) { if (String(c.body.password).length < 6) bad('Passwort mindestens 6 Zeichen'); run('UPDATE users SET pass=? WHERE id=?', C.hashPw(c.body.password), id); }
  if (c.body.role && isAdmin(c)) {
    if (c.body.role !== 'admin' && get("SELECT COUNT(*) n FROM users WHERE role='admin' AND id<>?", id).n === 0) bad('Es muss mindestens einen Administrator geben');
    run('UPDATE users SET role=? WHERE id=?', c.body.role === 'admin' ? 'admin' : 'user', id);
  }
  return {};
});
route('DELETE', '/api/users/(\\d+)', c => {
  if (!isAdmin(c)) bad('Nur Administratoren', 403);
  const id = +c.m[1], rest = get('SELECT COUNT(*) n FROM users WHERE id<>?', id).n;
  if (rest === 0 && S.getSetting('lan', false)) bad('Schalte zuerst die Netzwerk-Freigabe aus.');
  if (get("SELECT COUNT(*) n FROM users WHERE role='admin' AND id<>?", id).n === 0 && rest > 0) bad('Es muss mindestens einen Administrator geben');
  run('DELETE FROM users WHERE id=?', id);
  for (const [t, s] of R.sessions) if (s.id === id) R.sessions.delete(t);
  return {};
});

// ----- Verschlüsselung der Datei -----
route('POST', '/api/security/encryption', c => {
  if (!isAdmin(c)) bad('Nur Administratoren', 403);
  try {
    if (c.body.enable) S.enableEncryption(String(c.body.password || '')); else S.disableEncryption(String(c.body.password || ''));
  } catch (e) { bad(e.message); }
  return { encrypted: S.isEncrypted() };
});

// ----- Sicherungen -----
route('GET', '/api/backups', () => ({ list: S.listBackups(), dir: S.BACKUPS, second: S.getSetting('backup_dir2', '') }));
route('POST', '/api/backup', () => S.backup('-manuell'));
route('POST', '/api/backups/restore', c => { if (!isAdmin(c)) bad('Nur Administratoren', 403); try { S.restore(String(c.body.name)); } catch (e) { bad(e.message); } return {}; });
module.exports = { userCount };
