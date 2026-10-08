"""Test-Werkzeuge: temporäre Instanzen, HTTP-Client, Fake-Server (SMTP, Stripe, PayPal)."""
import os, re, shutil, subprocess, sys, tempfile, threading, time, json, socketserver, http.server, http.cookiejar, urllib.request, urllib.parse, urllib.error, hmac, hashlib, base64, struct

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EXCLUDE = {'storage', 'tests', 'dist', 'updates', '.git'}

class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Client:
    def __init__(self, base):
        self.base = base; self.cj = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj), NoRedir())
        self.ua = 'TestBrowser/1.0'
    def req(self, route='', params=None, data=None, headers=None, raw=None, method=None, path='/index.php', multipart=None):
        q = {'r': route} if route else {}
        if params: q.update(params)
        url = self.base + path + ('?' + urllib.parse.urlencode(q) if q else '')
        body = None; hd = {'User-Agent': self.ua}; hd.update(headers or {})
        if data is not None: body = urllib.parse.urlencode(data, doseq=True).encode()
        if raw is not None: body = raw
        if multipart is not None:
            b = '----tb' + str(time.time()); parts = []
            for k, v in multipart.get('fields', {}).items(): parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            for k, (fn, content) in multipart.get('files', {}).items(): parts.append((f'--{b}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: application/octet-stream\r\n\r\n').encode() + content + b'\r\n')
            parts.append(f'--{b}--\r\n'.encode()); body = b''.join(parts); hd['Content-Type'] = 'multipart/form-data; boundary=' + b
        rq = urllib.request.Request(url, data=body, headers=hd, method=method)
        try:
            r = self.op.open(rq, timeout=60); code = r.getcode(); data_ = r.read(); h = r.headers
        except urllib.error.HTTPError as e:
            code = e.code; data_ = e.read(); h = e.headers
        return Resp(code, data_, h)
    def csrf(self, route='dashboard', params=None):
        r = self.req(route, params); m = re.search(r'name="csrf" value="([^"]+)"', r.text)
        if not m: r = self.req('profile'); m = re.search(r'name="csrf" value="([^"]+)"', r.text)
        return m.group(1) if m else None
    def post(self, route, data=None, page='dashboard', pageparams=None, params=None, files=None):
        tok = self.csrf(page, pageparams)
        d = dict(data or {}); d['csrf'] = tok
        if files is not None: return self.req(route, params, multipart={'fields': d, 'files': files})
        return self.req(route, params, d)
    def get(self, route, params=None): return self.req(route, params)

class Resp:
    def __init__(self, code, raw, headers):
        self.code = code; self.raw = raw; self.headers = headers
    @property
    def text(self): return self.raw.decode('utf-8', 'replace')
    @property
    def loc(self): return self.headers.get('Location', '')
    def route(self):
        m = re.search(r'[?&]r=([a-z0-9_]+)', self.loc); return m.group(1) if m else None
    def flash(self): return re.findall(r'class="msg (?:ok|err|warn)">([^<]*)<', self.text)

class Instance:
    """Eine Kopie des Programms in einem Temp-Ordner, bedient von php -S."""
    def __init__(self, port, name='inst'):
        self.dir = tempfile.mkdtemp(prefix=name + '_'); self.port = port
        for it in os.listdir(ROOT):
            if it in EXCLUDE: continue
            s = os.path.join(ROOT, it); d = os.path.join(self.dir, it)
            shutil.copytree(s, d, ignore=shutil.ignore_patterns('__pycache__')) if os.path.isdir(s) else shutil.copy2(s, d)
        os.makedirs(os.path.join(self.dir, 'storage'))
        for f in ('.htaccess', 'index.html'): shutil.copy2(os.path.join(ROOT, 'storage', f), os.path.join(self.dir, 'storage', f))
        self.proc = None; self.base = f'http://127.0.0.1:{port}'; self.mysql = None
    def start(self):
        self.proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{self.port}', '-t', self.dir], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=dict(os.environ, PHP_CLI_SERVER_WORKERS='4'))
        for _ in range(50):
            try: urllib.request.urlopen(self.base + '/index.php', timeout=1); break
            except urllib.error.HTTPError: break
            except Exception: time.sleep(0.1)
    def stop(self):
        if self.proc: self.proc.terminate(); self.proc.wait()
    def client(self): return Client(self.base)
    def php(self, code):
        return subprocess.run(['php', '-r', code], capture_output=True, text=True, cwd=self.dir).stdout
    def _q(self, v):
        if isinstance(v, (int, float)): return str(v)
        if v is None: return 'NULL'
        return "'" + str(v).replace('\\', '\\\\').replace("'", "\\'") + "'"
    def sql(self, db, query, params=()):
        if self.mysql:
            parts = query.split('?'); q = parts[0]
            for i, pv in enumerate(params): q += self._q(pv) + parts[i + 1]
            out = subprocess.run(['mysql', '-uroot', self.mysql, '-B', '-e', q], capture_output=True, text=True)
            if out.returncode != 0: raise RuntimeError(out.stderr)
            lines = out.stdout.rstrip('\n').split('\n') if out.stdout.strip() else []
            if len(lines) < 1: return []
            hdr = lines[0].split('\t'); rows = []
            for ln in lines[1:]:
                vals = ln.split('\t'); unesc = lambda x: re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t', '\\': '\\', '0': '\0'}.get(m.group(1), m.group(1)), x); rows.append({h: (int(v) if re.fullmatch(r'-?\d+', v) else (None if v == 'NULL' else unesc(v))) for h, v in zip(hdr, vals)})
            return rows
        import sqlite3
        con = sqlite3.connect(os.path.join(self.dir, 'storage', db)); con.row_factory = sqlite3.Row
        cur = con.execute(query, params); rows = [dict(r) for r in cur.fetchall()]; con.commit(); con.close(); return rows
    def errlog(self):
        p = os.path.join(self.dir, 'storage', 'logs', 'php-error.log')
        return open(p).read() if os.path.exists(p) else ''
    def cron(self, *args):
        return subprocess.run(['php', os.path.join(self.dir, 'cron.php'), *args], capture_output=True, text=True, cwd=self.dir)

def totp(secret_b32, t=None):
    key = base64.b32decode(secret_b32.replace(' ', '').upper() + '=' * (-len(secret_b32.replace(' ', '')) % 8))
    step = int((t or time.time()) // 30); h = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = h[19] & 0xf; v = ((h[o] & 0x7f) << 24) | (h[o+1] << 16) | (h[o+2] << 8) | h[o+3]
    return str(v % 1000000).zfill(6)

# ------------------------------------------------------------------ Fake-Server
class FakeSMTP:
    def __init__(self, port):
        self.messages = []; outer = self
        class H(socketserver.StreamRequestHandler):
            def w(self, s): self.wfile.write((s + '\r\n').encode())
            def handle(self):
                self.w('220 fake'); data = None; auth = 0; rcpt = []
                while True:
                    l = self.rfile.readline()
                    if not l: break
                    c = l.decode(errors='replace').rstrip('\r\n')
                    if data is not None:
                        if c == '.': outer.messages.append({'rcpt': rcpt, 'raw': b'\r\n'.join(data).decode('utf-8', 'replace')}); data = None; self.w('250 ok'); continue
                        data.append(l.rstrip(b'\r\n')); continue
                    u = c.upper()
                    if auth == 1: auth = 2; self.w('334 UGFzc3dvcmQ6'); continue
                    if auth == 2: auth = 0; self.w('235 ok'); continue
                    if u.startswith('EHLO'): self.w('250-fake'); self.w('250 AUTH LOGIN')
                    elif u.startswith('AUTH LOGIN'): auth = 1; self.w('334 VXNlcm5hbWU6')
                    elif u.startswith('RCPT'): rcpt.append(re.sub(r'.*<(.*)>.*', r'\1', c)); self.w('250 ok')
                    elif u.startswith('DATA'): self.w('354 go'); data = []
                    elif u.startswith('QUIT'): self.w('221 bye'); break
                    else: self.w('250 ok')
        socketserver.ThreadingTCPServer.allow_reuse_address = True
        self.srv = socketserver.ThreadingTCPServer(('127.0.0.1', port), H); threading.Thread(target=self.srv.serve_forever, daemon=True).start()
    def stop(self): self.srv.shutdown(); self.srv.server_close()
    def texts(self):
        import email, email.policy
        out = []
        for mm in self.messages:
            m = email.message_from_string(mm['raw'], policy=email.policy.default)
            body = ''.join(p.get_content() for p in m.walk() if p.get_content_type() == 'text/plain')
            out.append({'to': ' '.join(mm['rcpt']), 'subject': str(m['Subject']), 'body': body, 'raw': mm['raw']})
        return out
    def last_body(self):
        raw = self.messages[-1]['raw'] if self.messages else ''
        import email, email.policy
        m = email.message_from_string(raw, policy=email.policy.default)
        for p in m.walk():
            if p.get_content_type() == 'text/plain': return p.get_content()
        return ''

class FakeProviders:
    """Stripe- und PayPal-API-Attrappe auf einem Port (Pfade unterscheiden sich)."""
    def __init__(self, port):
        self.calls = []; self.paypal_custom = {}; outer = self
        class H(http.server.BaseHTTPRequestHandler):
            def log_message(self, *a): pass
            def _send(self, obj, code=200):
                b = json.dumps(obj).encode(); self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
            def _body(self):
                n = int(self.headers.get('Content-Length') or 0); return self.rfile.read(n).decode() if n else ''
            def do_GET(self): self.route('GET')
            def do_POST(self): self.route('POST')
            def route(self, m):
                p = self.path.split('?')[0]; body = self._body(); auth = self.headers.get('Authorization', '')
                outer.calls.append({'m': m, 'p': p, 'body': body, 'auth': auth})
                if p == '/v1/prices': return self._send({'id': 'price_FAKE1'})
                if p == '/v1/checkout/sessions' and m == 'POST': return self._send({'id': 'cs_FAKE1', 'url': f'http://127.0.0.1:{port}/pay/stripe'})
                if p.startswith('/v1/checkout/sessions/') and m == 'GET':
                    fs = [c for c in outer.calls if c['p'] == '/v1/checkout/sessions' and c['m'] == 'POST']
                    if not fs: return self._send({'error': {'message': 'No such session'}}, 404)
                    f = fs[-1]; q = urllib.parse.parse_qs(f['body'])
                    return self._send({'id': 'cs_FAKE1', 'status': 'complete', 'client_reference_id': q['client_reference_id'][0], 'customer': 'cus_FAKE1', 'subscription': 'sub_FAKE1', 'metadata': {'tenant_id': q['metadata[tenant_id]'][0], 'plan_id': q['metadata[plan_id]'][0]}})
                if p == '/v1/billing_portal/sessions': return self._send({'url': f'http://127.0.0.1:{port}/portal'})
                if p.startswith('/v1/subscriptions/'): return self._send({'id': 'sub_FAKE1', 'cancel_at_period_end': True})
                if p == '/v1/oauth2/token': return self._send({'access_token': 'PPTOKEN', 'expires_in': 3000})
                if p == '/v1/catalogs/products': return self._send({'id': 'PROD-FAKE'}, 201)
                if p == '/v1/billing/plans': return self._send({'id': 'P-FAKE1'}, 201)
                if p == '/v1/billing/subscriptions' and m == 'POST':
                    j = json.loads(body); outer.paypal_custom['I-FAKE1'] = j['custom_id']
                    return self._send({'id': 'I-FAKE1', 'status': 'APPROVAL_PENDING', 'links': [{'rel': 'approve', 'href': f'http://127.0.0.1:{port}/pay/paypal'}]}, 201)
                if p == '/v1/billing/subscriptions/I-FAKE1' and m == 'GET': return self._send({'id': 'I-FAKE1', 'status': 'ACTIVE', 'custom_id': outer.paypal_custom.get('I-FAKE1', '')})
                if p == '/v1/billing/subscriptions/I-FAKE1/cancel': self.send_response(204); self.end_headers(); return
                if p == '/v1/notifications/verify-webhook-signature':
                    j = json.loads(body); return self._send({'verification_status': 'SUCCESS' if j.get('webhook_id') == 'WH-TEST' and j.get('transmission_sig') == 'goodsig' else 'FAILURE'})
                self._send({'error': 'unknown ' + p}, 404)
        self.srv = http.server.ThreadingHTTPServer(('127.0.0.1', port), H); threading.Thread(target=self.srv.serve_forever, daemon=True).start()
    def stop(self): self.srv.shutdown(); self.srv.server_close()

def stripe_sig(payload, secret, t=None):
    t = int(t or time.time()); return f't={t},v1=' + hmac.new(secret.encode(), f'{t}.{payload}'.encode(), hashlib.sha256).hexdigest()
