// Verschlüsselung (AES-256-GCM, Schlüssel per scrypt aus dem Passwort) und Passwort-Hashes
const crypto = require('node:crypto');
const MAGIC = Buffer.from('KBE1');
const SCRYPT = { N: 2 ** 15, r: 8, p: 1, maxmem: 96 * 1024 * 1024 };

const deriveKey = (pw, salt) => crypto.scryptSync(String(pw), salt, 32, SCRYPT);
function encrypt(buf, key) {
  const iv = crypto.randomBytes(12);
  const c = crypto.createCipheriv('aes-256-gcm', key, iv);
  const ct = Buffer.concat([c.update(buf), c.final()]);
  return Buffer.concat([MAGIC, iv, c.getAuthTag(), ct]);
}
function decrypt(buf, key) {
  if (buf.length < 32 || !buf.subarray(0, 4).equals(MAGIC)) throw new Error('Keine verschlüsselte Datei');
  const d = crypto.createDecipheriv('aes-256-gcm', key, buf.subarray(4, 16));
  d.setAuthTag(buf.subarray(16, 32));
  return Buffer.concat([d.update(buf.subarray(32)), d.final()]);
}
function hashPw(pw) {
  const salt = crypto.randomBytes(16);
  return salt.toString('hex') + ':' + crypto.scryptSync(String(pw), salt, 32, SCRYPT).toString('hex');
}
function verifyPw(pw, stored) {
  const [s, h] = String(stored || '').split(':');
  if (!s || !h) return false;
  const a = crypto.scryptSync(String(pw), Buffer.from(s, 'hex'), 32, SCRYPT), b = Buffer.from(h, 'hex');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
module.exports = { deriveKey, encrypt, decrypt, hashPw, verifyPw, randomToken: () => crypto.randomBytes(24).toString('hex') };
