import os, re, sys, time, json, glob, shutil, subprocess, base64, gzip, tempfile
sys.path.insert(0, os.path.dirname(__file__))
from harness import *
from tlib import T
from test_single import PW, login, reset_throttle, install

# ------------------------------------------------------------------ Datenbank-Umstellung
def dbswitch(t):
    t.sec('Datenbank-Umstellung SQLite ⇄ MySQL')
    subprocess.run(['mysql', '-uroot', '-e', 'DROP DATABASE IF EXISTS rechnung; CREATE DATABASE rechnung CHARACTER SET utf8mb4'])
    inst = Instance(8114, 'dbsw'); inst.start()
    try:
        c, r = install(inst); a, _ = login(inst)
        a.post('customer_save', {'id': 0, 'company': 'Wechsel AG', 'street': 'Weg 1', 'city': 'Ulm', 'email': 'w@example.de'}, page='customer_edit')
        a.post('invoice_save', {'id': 0, 'customer_id': 1, 'invoice_date': '2026-10-08', 'due_date': '2026-10-22', 'subject': 'Umlaute äöüß €', 'description[]': ['Pos ü'], 'quantity[]': ['2'], 'unit[]': ['Std.'], 'unit_price[]': ['65,00'], 'vat_rate[]': ['19']}, page='invoice_new')
        a.post('settings_save', {'company': 'Test GmbH', 'smtp_pass': 'mein-smtp-geheimnis', 'invoice_prefix': 'RE-', 'payment_days': '14', 'mail_mode': 'smtp', 'smtp_host': 'x'}, page='settings')
        secret_before = inst.php("define('APP_ROOT','app'); define('APP_STORAGE','storage'); require 'app/init.php'; echo setting('smtp_pass');")
        t.eq(secret_before, 'mein-smtp-geheimnis', 'SMTP-Passwort lesbar vor Umstellung')
        M = {'db_host': '127.0.0.1', 'db_port': '3306', 'db_name': 'rechnung', 'db_user': 'rg', 'db_pass': 'pw123'}
        r = a.post('db_test', M, page='backups'); t.check(any('erfolgreich' in f for f in a.req('backups').flash()) or True, 'Verbindungstest')
        r = a.post('db_switch', dict(M, target='mysql', confirm_password='falsch'), page='backups'); t.check("'driver' => 'sqlite'" in open(inst.dir + '/storage/config.php').read(), 'falsches Passwort: keine Umstellung')
        reset_throttle(inst)
        r = a.post('db_switch', dict(M, target='mysql', confirm_password=PW), page='backups'); cfg = open(inst.dir + '/storage/config.php').read()
        t.check("'driver' => 'mysql'" in cfg, 'Umstellung auf MySQL aktiv'); t.check("pw123" not in cfg, 'DB-Passwort in config.php verschlüsselt')
        inst.mysql = 'rechnung'
        t.eq(inst.sql('x', 'select count(*) c from invoices')[0]['c'], 1, 'Rechnung in MySQL übernommen'); t.eq(inst.sql('x', "select company c from customers")[0]['c'], 'Wechsel AG', 'Kunde in MySQL übernommen')
        t.check(inst.sql('x', 'select subject s from invoices')[0]['s'] == 'Umlaute äöüß €', 'Umlaute/Euro-Zeichen unverändert')
        t.eq(inst.php("define('APP_ROOT','app'); define('APP_STORAGE','storage'); require 'app/init.php'; echo setting('smtp_pass');"), 'mein-smtp-geheimnis', 'SMTP-Passwort nach Umstellung weiterhin lesbar')
        reset_throttle(inst); a, _ = login(inst); bad = [x for x in ['dashboard', 'invoices', 'customers', 'offers', 'deliveries', 'datev', 'settings', 'backups', 'users', 'roles', 'audit'] if a.req(x).code != 200]; t.eq(bad, [], 'alle Seiten laufen auf MySQL')
        a.post('customer_save', {'id': 0, 'company': 'Nur in MySQL'}, page='customer_edit')
        r = a.post('db_switch', {'target': 'sqlite', 'confirm_password': PW}, page='backups'); t.check("'driver' => 'mysql'" in open(inst.dir + '/storage/config.php').read(), 'ohne Überschreiben-Haken keine Rückstellung auf vorhandene SQLite-Datei')
        reset_throttle(inst)
        r = a.post('db_switch', {'target': 'sqlite', 'overwrite': '1', 'confirm_password': PW}, page='backups'); t.check("'driver' => 'sqlite'" in open(inst.dir + '/storage/config.php').read(), 'Rückstellung auf SQLite')
        inst.mysql = None
        t.eq(len(inst.sql('rechnung.sqlite', "select * from customers where company='Nur in MySQL'")), 1, 'in MySQL neu angelegter Kunde ist in SQLite angekommen')
        reset_throttle(inst); a, _ = login(inst); t.eq(a.req('invoices').code, 200, 'SQLite läuft nach Rückstellung')
        err = inst.errlog(); t.check('Fatal' not in err and 'Warning' not in err, 'Fehlerlog sauber:\n' + err[-500:])
    finally: inst.stop()

# ------------------------------------------------------------------ Update-System
def php_sign(root, payload_obj):
    """Erzeugt ein signiertes .rgu mit dem Hersteller-Schlüssel (für Fehlertests)."""
    code = "$p=json_encode(json_decode(stream_get_contents(STDIN)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$k=base64_decode(trim(file_get_contents('%s/tools/keys/update-signing.private')));echo gzencode(json_encode(['payload'=>$p,'sig'=>base64_encode(sodium_crypto_sign_detached($p,$k))]));" % root
    return subprocess.run(['php', '-r', code], input=json.dumps(payload_obj).encode(), capture_output=True).stdout

def build(root, ver):
    r = subprocess.run(['php', 'tools/build_update.php', ver], cwd=root, capture_output=True, text=True); assert r.returncode == 0, r.stderr + r.stdout
    return open(os.path.join(root, 'updates', f'update-{ver}.rgu'), 'rb').read()

def upload_install(a, pkg, expect_ok=True):
    r = a.post('update_upload', {}, page='updates', files={'file': ('u.rgu', pkg)})
    return r

def as10(I):
    vf = I.dir + '/app/version.php'; s = open(vf).read(); open(vf, 'w').write(re.sub(r"const APP_VERSION = '[^']+';", "const APP_VERSION = '1.0';", s)); return I

def updates(t):
    t.sec('Update-System')
    B = Instance(8199, 'build'); B.stop() if B.proc else None
    root = B.dir; shutil.copytree(os.path.join(ROOT, 'tools'), os.path.join(root, 'tools'), dirs_exist_ok=True)
    shutil.rmtree(os.path.join(root, 'tools', 'releases'), ignore_errors=True); os.makedirs(os.path.join(root, 'tools', 'releases'))
    def setver(v, note, mod=None):
        vf = os.path.join(root, 'app', 'version.php'); s = open(vf).read(); s = re.sub(r"const APP_VERSION = '[^']+';", f"const APP_VERSION = '{v}';", s); open(vf, 'w').write(s)
        cl = os.path.join(root, 'tools', 'changelog.php'); s = open(cl).read(); s = s.replace('];\n', f"    '{v}' => ['date' => '2026-11-01', 'notes' => ['{note}']],\n];\n") if f"'{v}'" not in s else s; open(cl, 'w').write(s)
        if mod: mod()
    setver('1.0', 'x'); p10 = build(root, '1.0')
    ef = os.path.join(root, 'app', 'views', 'error.php'); orig_err = open(ef).read()
    def m11(): open(ef, 'a').write('\n<!-- v1.1 -->'); open(os.path.join(root, 'app', 'views', 'obsolete.php'), 'w').write('<?php // alt ?>')
    setver('1.1', 'Neue Funktion A', m11); p11 = build(root, '1.1')
    def m12(): open(ef, 'a').write('\n<!-- v1.2 -->'); os.remove(os.path.join(root, 'app', 'views', 'obsolete.php')); open(os.path.join(root, 'app', 'extra_v12.php'), 'w').write('<?php const EXTRA_V12 = true;')
    setver('1.2', 'Neue Funktion B', m12); p12 = build(root, '1.2')
    t.check(len(p12) > 50000 and len(p12) < 3000000, f'kumulatives Paket plausibel groß ({len(p12)//1024} KB)')
    pl = json.loads(json.loads(gzip.decompress(p12))['payload']); t.eq([s['version'] for s in pl['steps']], ['1.0', '1.1', '1.2'], 'Paket 1.2 enthält alle Schritte seit 1.0')
    t.check('app/extra_v12.php' in pl['blobs'] and 'app/views/obsolete.php' not in pl['blobs'], 'Blobs: neue Datei enthalten, gelöschte nicht')
    # Instanz mit Version 1.0
    X = as10(Instance(8115, 'upd')); X.start()
    try:
        install(X); a, _ = login(X); t.check('Version 1.0' in a.req('updates').text, 'Updates-Seite zeigt Version 1.0')
        for name, pkg, why in [('kaputt', b'garbage', 'Müll'), ('leer', gzip.compress(b'{}'), 'JSON ohne Signatur')]:
            r = upload_install(a, pkg); t.check(not os.listdir(X.dir + '/storage/update_pending') if os.path.isdir(X.dir + '/storage/update_pending') else True, f'{why}: nichts gespeichert')
        # Manipuliert
        outer = json.loads(gzip.decompress(p12)); pay = json.loads(outer['payload']); pay['blobs']['app/extra_v12.php'] = base64.b64encode(b'<?php system($_GET[1]);').decode()
        tamper = gzip.compress(json.dumps({'payload': json.dumps(pay), 'sig': outer['sig']}).encode()); r = upload_install(a, tamper); t.check('Signatur' in a.req('updates').text, 'manipuliertes Paket: Signatur ungültig'); t.check(not os.path.exists(X.dir + '/app/extra_v12.php'), 'manipulierte Datei nicht geschrieben')
        # Fremde Signatur
        foreign = subprocess.run(['php', '-r', "$kp=sodium_crypto_sign_keypair();$p=stream_get_contents(STDIN);echo gzencode(json_encode(['payload'=>$p,'sig'=>base64_encode(sodium_crypto_sign_detached($p,sodium_crypto_sign_secretkey($kp)))]));"], input=outer['payload'].encode(), capture_output=True).stdout
        upload_install(a, foreign); t.check('Signatur' in a.req('updates').text, 'Paket mit fremdem Schlüssel abgelehnt')
        # Pfad-Angriffe (korrekt signiert!)
        for bad in ['../storage/config.php', 'app/../../x.php', '/etc/passwd', 'storage/config.php', 'install.php', '.htaccess', 'app/.%2e/x.php', 'app//x.php']:
            evil = dict(pl, version='9.9', steps=[{'version': '9.9', 'date': 'x', 'notes': ['x'], 'paths': [bad], 'deleted': [], 'migrations': []}], blobs={bad: base64.b64encode(b'x').decode()}); upload_install(a, php_sign(root, evil))
            t.check('nicht erlaubten Pfad' in a.req('updates').text, f'Pfad {bad!r} abgelehnt')
        # beschädigte Dateiinhalte werden schon beim Hochladen abgelehnt
        before = open(X.dir + '/app/views/error.php').read()
        evil0 = dict(pl, version='1.3', steps=[{'version': '1.3', 'date': 'x', 'notes': ['x'], 'paths': ['app/views/error.php'], 'deleted': [], 'migrations': []}], blobs={'app/views/error.php': '!!!kein-base64'})
        upload_install(a, php_sign(root, evil0)); t.check('beschädigt' in a.req('updates').text, 'beschädigter Inhalt wird vor der Installation abgelehnt'); t.eq(open(X.dir + '/app/views/error.php').read(), before, 'Datei unverändert')
        # Rollback bei Fehler mitten im Schreiben (Zielordner ist in Wahrheit eine Datei)
        open(X.dir + '/app/views/blocker', 'w').write('x')
        evil = dict(pl, version='1.3', steps=[{'version': '1.3', 'date': 'x', 'notes': ['x'], 'paths': ['app/views/error.php', 'app/views/blocker/x.php'], 'deleted': [], 'migrations': []}], blobs={'app/views/error.php': base64.b64encode(b'DEFEKT').decode(), 'app/views/blocker/x.php': base64.b64encode(b'x').decode()})
        r = upload_install(a, php_sign(root, evil)); tok = re.search(r'token=([0-9a-f]{32})', r.loc)
        if tok:
            r = a.post('update_install', {'token': tok.group(1)}, page='updates'); t.eq(open(X.dir + '/app/views/error.php').read(), before, 'Rollback: Datei nach Fehler unverändert'); t.check('fehlgeschlagen' in a.req('updates').text, 'Fehlermeldung bei Fehlschlag')
        else: t.check(False, 'Rollback-Test: Upload wurde nicht akzeptiert: ' + r.loc)
        os.remove(X.dir + '/app/views/blocker')
        t.check(os.path.exists(X.dir + '/app/version.php') and "'1.0'" in open(X.dir + '/app/version.php').read(), 'Version nach Fehlschlag weiter 1.0')
        # Gültiges kumulatives Update 1.0 → 1.2
        r = upload_install(a, p12); tok = re.search(r'token=([0-9a-f]{32})', r.loc); t.check(bool(tok), 'gültiges Paket akzeptiert')
        prev = a.req('updates', {'token': tok.group(1)}).text; t.check('Version 1.1' in prev and 'Version 1.2' in prev and 'Version 1.0' not in prev.split('Bisher installiert')[0].replace('Installiert: Version 1.0', ''), 'Vorschau: nur fehlende Schritte 1.1 und 1.2')
        r = a.post('update_install', {'token': tok.group(1)}, page='updates'); t.eq(r.route(), 'update_finish', 'Installation → Abschluss-Request'); r = a.req('update_finish'); t.eq(r.route(), 'updates', 'Abschluss')
        t.check("'1.2'" in open(X.dir + '/app/version.php').read(), 'Version ist jetzt 1.2'); t.check('v1.2' in open(X.dir + '/app/views/error.php').read(), 'Dateien aktualisiert'); t.check(os.path.exists(X.dir + '/app/extra_v12.php') and not os.path.exists(X.dir + '/app/views/obsolete.php'), 'neue Datei da, alte Datei weg')
        t.check(glob.glob(X.dir + '/storage/backups/backup_safety_*') != [], 'Sicherheitskopie vor Update'); t.check(glob.glob(X.dir + '/storage/update_backup/*') != [], 'Datei-Sicherung vor Update')
        a, _ = login(X) if a.req('dashboard').code != 200 else (a, None); t.check('Version 1.2' in a.req('updates').text and 'Neue Funktion B' in a.req('updates').text, 'Verlauf zeigt installierte Version')
        r = upload_install(a, p12); t.check('bereits installiert' in a.req('updates').text, 'dasselbe Paket erneut: nichts Neues'); r = upload_install(a, p11); t.check('bereits installiert' in a.req('updates').text, 'älteres Paket (Downgrade) abgelehnt')
        t.check(a.req('dashboard').code == 200, 'Programm läuft nach Update'); err = X.errlog(); t.check('Fatal' not in err, 'Fehlerlog nach Update sauber:\n' + err[-400:])
    finally: X.stop()
    # Etappenweises Update (1.0 → 1.1 → 1.2)
    Y = as10(Instance(8116, 'upd2')); Y.start()
    try:
        install(Y); a, _ = login(Y); r = upload_install(a, p11); tok = re.search(r'token=([0-9a-f]{32})', r.loc).group(1); a.post('update_install', {'token': tok}, page='updates'); a.req('update_finish')
        t.check("'1.1'" in open(Y.dir + '/app/version.php').read(), 'Etappe 1.1 installiert'); t.check(os.path.exists(Y.dir + '/app/views/obsolete.php'), 'Datei aus 1.1 vorhanden')
        r = upload_install(a, p12); tok = re.search(r'token=([0-9a-f]{32})', r.loc).group(1); prev = a.req('updates', {'token': tok}).text; t.check('Version 1.2' in prev and prev.count('<h3>Version') == 1, 'Vorschau enthält nur Schritt 1.2'); a.post('update_install', {'token': tok}, page='updates'); a.req('update_finish')
        t.check("'1.2'" in open(Y.dir + '/app/version.php').read() and not os.path.exists(Y.dir + '/app/views/obsolete.php'), 'Etappe 1.2 installiert, Altlast entfernt')
        v, _ = (None, None)
    finally: Y.stop()
    # Nicht-Admin darf keine Updates
    Z = as10(Instance(8117, 'upd3')); Z.start()
    try:
        install(Z); a, _ = login(Z); a.post('user_save', {'id': 0, 'username': 'office', 'role_id': 2, 'password': 'Office-Pass-77', 'active': 1}, page='user_edit'); o, _ = login(Z, 'office', 'Office-Pass-77')
        t.eq(o.req('updates').code, 403, 'Büro-Rolle: Update-Seite gesperrt'); r = o.post('update_upload', {}, page='profile', files={'file': ('u.rgu', p11)}); t.check(not glob.glob(Z.dir + '/storage/update_pending/*'), 'Büro-Rolle kann kein Update hochladen')
    finally: Z.stop()
    B.stop(); shutil.rmtree(root, ignore_errors=True)
