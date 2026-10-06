// Gemeinsamer Zustand zwischen server.js und admin.js
module.exports = { sessions: new Map(), relisten: () => {}, bind: () => ({}), afterUnlock: () => {}, fails: new Map() };
