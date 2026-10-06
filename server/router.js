const routes = [];
const route = (method, re, fn, opts = {}) => routes.push({ method, re: new RegExp('^' + re + '$'), fn, opts });
class HttpError extends Error { constructor(code, msg) { super(msg); this.code = code; } }
const bad = (msg, code = 400) => { throw new HttpError(code, msg); };
module.exports = { routes, route, HttpError, bad };
