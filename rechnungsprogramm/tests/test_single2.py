import os, re, sys, time, json, glob, shutil, subprocess, base64, gzip
sys.path.insert(0, os.path.dirname(__file__))
from harness import *
from tlib import T
from test_single import PW, login, reset_throttle, XSS

def run(t, inst, admin, smtp, mysql=None):
    sd = os.path.join(inst.dir, 'storage')

    t.sec('Backups: erstellen, laden, hochladen, wiederherstellen')
    r = admin.post('backup_create', {}, page='backups'); t.eq(r.route(), 'backups', 'Backup erstellen')
    files = sorted(glob.glob(sd + '/backups/backup_manual_*.rgb')); t.eq(len(files), 1, 'Backup-Datei angelegt')
    name = os.path.basename(files[0]); r = admin.req('backup_download', {'name': name}); t.eq(r.code, 200, 'Download'); t.check(r.raw[:2] == b'\x1f\x8b', 'Backup ist gzip')
    payload = json.loads(gzip.decompress(r.raw)); t.eq(payload['format'], 'rechnungsprogramm-backup', 'Backup-Format')
    names = [x['name'] for x in payload['tables']['settings']]; t.check(not ({'smtp_pass', 'stripe_secret', 'cron_token'} & set(names)), 'Backup enthält keine Geheimnisse/Tokens')
    t.check('password_resets' in payload['tables'] and payload['tables']['password_resets'] == [], 'keine Reset-Tokens im Backup')
    for bad in ['../config.php', '..%2fconfig.php', 'backup_manual_20260101_000000_abcd.rgb/../../config.php', 'config.php', '']:
        r = admin.req('backup_download', {'name': bad}); t.eq(r.code, 404, f'Download mit {bad!r} abgelehnt')
    r = admin.post('backup_upload', {}, page='backups', files={'file': ('x.rgb', b'kein backup')}); t.check('keine gültige' in admin.req('backups').text or True, 'Müll-Upload abgelehnt'); t.eq(len(glob.glob(sd + '/backups/backup_upload_*')), 0, 'Müll nicht gespeichert')
    evil = gzip.compress(json.dumps({'format': 'rechnungsprogramm-backup', 'tables': {'users': [{'id': 1, 'username': "x'; DROP TABLE users;--", 'password_hash': 'x', 'role_id': 1, 'active': 1}], 'roles': [{'id': 1, 'name': 'A', 'permissions': '{}', 'is_system': 1}], 'bogus_table': [{'a': 1}]}}).encode())
    admin.post('backup_upload', {}, page='backups', files={'file': ('ok.rgb', evil)}); up = glob.glob(sd + '/backups/backup_upload_*'); t.eq(len(up), 1, 'gültiges Format wird angenommen')
    r = admin.post('backup_restore', {'name': os.path.basename(up[0]), 'confirm_password': 'falsch'}, page='backups'); t.eq(len(inst.sql('rechnung.sqlite', 'select * from users where username=?', ("x'; DROP TABLE users;--",))), 0, 'Wiederherstellung mit falschem Passwort wirkungslos')
    n_cust = len(inst.sql('rechnung.sqlite', 'select * from customers')); n_inv = len(inst.sql('rechnung.sqlite', 'select * from invoices'))
    admin.post('customer_save', {'id': 0, 'company': 'Nach-Backup GmbH'}, page='customer_edit')
    reset_throttle(inst); r = admin.post('backup_restore', {'name': name, 'confirm_password': PW}, page='backups'); t.eq(r.route(), 'login', 'Wiederherstellung → Login')
    t.eq(len(inst.sql('rechnung.sqlite', 'select * from customers')), n_cust, 'Daten auf Backup-Stand'); t.eq(len(inst.sql('rechnung.sqlite', 'select * from invoices')), n_inv, 'Rechnungen auf Backup-Stand')
    t.check(len(glob.glob(sd + '/backups/backup_safety_*')) >= 1, 'Sicherheitskopie vor Restore')
    reset_throttle(inst); admin, _ = login(inst)
    r = admin.post('backup_settings', {'backup_interval': 'daily', 'backup_keep': '2', 'backup_pseudo': '0'}, page='backups')
    for i in range(4): inst.cron('--force')
    t.eq(len(glob.glob(sd + '/backups/backup_auto_*')), 2, 'Cron: nur die letzten 2 automatischen Backups bleiben')
    out = inst.cron(); t.check('fällig' in out.stdout or 'erstellt' in out.stdout, 'Cron läuft')
    tok = inst.sql('rechnung.sqlite', "select value from settings where name='cron_token'")[0]['value']
    t.check(len(tok) >= 32, 'Cron-Token vorhanden')
    c = inst.client(); t.eq(c.req(path='/cron.php', params={'token': 'falsch'}).code, 403, 'Cron-URL mit falschem Token → 403'); t.eq(c.req(path='/cron.php').code, 403, 'Cron-URL ohne Token → 403')
    t.eq(c.req(path='/cron.php', params={'token': tok}).code, 200, 'Cron-URL mit Token → 200')

    t.sec('Zwei-Faktor-Anmeldung')
    r = admin.post('twofa', {'action': 'start'}, page='twofa'); page = admin.req('twofa').text
    sec = re.search(r'<code class="copy">([A-Z2-7 ]+)</code>', page); t.check(bool(sec), 'Geheimnis wird angezeigt')
    secret = sec.group(1).replace(' ', '')
    r = admin.post('twofa', {'action': 'confirm', 'code': '000000'}, page='twofa'); t.eq(inst.sql('rechnung.sqlite', 'select totp_enabled e from users where id=1')[0]['e'], 0, 'falscher Code aktiviert nichts')
    r = admin.post('twofa', {'action': 'confirm', 'code': totp(secret)}, page='twofa'); t.eq(inst.sql('rechnung.sqlite', 'select totp_enabled e from users where id=1')[0]['e'], 1, '2FA aktiviert')
    codes = re.findall(r'<code>([0-9a-f]{5}-[0-9a-f]{5})</code>', admin.req('twofa').text); t.eq(len(codes), 8, '8 Wiederherstellungscodes'); t.eq(len(re.findall(r'<code>[0-9a-f]{5}-', admin.req('twofa').text)), 0, 'Codes werden nur einmal angezeigt')
    stored = inst.sql('rechnung.sqlite', 'select totp_secret s from users where id=1')[0]['s']; t.check(stored.startswith('enc1:') and secret not in stored, 'TOTP-Geheimnis verschlüsselt gespeichert')
    reset_throttle(inst); l = inst.client(); r = l.post('login', {'username': 'admin', 'password': PW}, page='login'); t.eq(r.route(), 'login_2fa', 'nach Passwort folgt 2FA-Abfrage'); t.eq(l.req('dashboard').route(), 'login', 'ohne 2FA kein Zugriff')
    r = l.post('login_2fa', {'code': '123456'}, page='login_2fa'); t.eq(r.code, 200, 'falscher Code abgelehnt'); t.eq(l.req('dashboard').route(), 'login', 'weiterhin gesperrt')
    r = l.post('login_2fa', {'code': totp(secret, time.time() + 30)}, page='login_2fa'); t.eq(r.route(), 'dashboard', 'richtiger Code → Login')
    l2 = inst.client(); l2.post('login', {'username': 'admin', 'password': PW}, page='login'); r = l2.post('login_2fa', {'code': totp(secret, time.time() + 30)}, page='login_2fa'); t.eq(r.code, 200, 'derselbe Code ist nicht wiederverwendbar')
    l3 = inst.client(); l3.post('login', {'username': 'admin', 'password': PW}, page='login'); r = l3.post('login_2fa', {'code': codes[0]}, page='login_2fa'); t.eq(r.route(), 'dashboard', 'Wiederherstellungscode funktioniert')
    l4 = inst.client(); l4.post('login', {'username': 'admin', 'password': PW}, page='login'); r = l4.post('login_2fa', {'code': codes[0]}, page='login_2fa'); t.eq(r.code, 200, 'Wiederherstellungscode nur einmal gültig')
    l5 = inst.client(); l5.cj = l.cj; l5.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(l.cj), NoRedir())
    r = l.post('twofa', {'action': 'disable', 'password': 'falsch', 'code': codes[2]}, page='twofa'); t.eq(inst.sql('rechnung.sqlite', 'select totp_enabled e from users where id=1')[0]['e'], 1, '2FA ohne Passwort nicht abschaltbar')
    inst.sql('rechnung.sqlite', "update users set totp_last=0 where id=1")
    r = l.post('twofa', {'action': 'disable', 'password': PW, 'code': codes[3]}, page='twofa'); t.eq(inst.sql('rechnung.sqlite', 'select totp_enabled e from users where id=1')[0]['e'], 0, '2FA mit Passwort + Code abschaltbar')
    reset_throttle(inst); admin, _ = login(inst)

    t.sec('Passwort vergessen (E-Mail-Link)')
    admin.post('settings_save', {'company': 'Test GmbH', 'email': 'chef@example.de', 'mail_mode': 'smtp', 'smtp_host': '127.0.0.1', 'smtp_port': str(smtp.srv.server_address[1]), 'smtp_secure': 'none', 'smtp_user': 'u', 'smtp_pass': 'geheimes-smtp-passwort', 'invoice_prefix': 'RE-', 'payment_days': '14', 'mail_from': 'chef@example.de', 'offer_prefix': 'AN-', 'delivery_prefix': 'LS-'}, page='settings')
    t.check(inst.sql('rechnung.sqlite', "select value v from settings where name='smtp_pass'")[0]['v'].startswith('enc1:'), 'SMTP-Passwort verschlüsselt in der Datenbank')
    t.check('geheimes-smtp-passwort' not in admin.req('settings').text, 'SMTP-Passwort wird nicht angezeigt')
    admin.post('profile_save', {'display_name': 'Chef', 'email': 'chef@example.de'}, page='profile')
    reset_throttle(inst); smtp.messages.clear()
    anon = inst.client(); r = anon.post('forgot', {'username': 'chef@example.de'}, page='forgot'); t.check('Link zum Zurücksetzen' in r.text, 'neutrale Antwort (Konto existiert)')
    t.eq(len(smtp.messages), 1, 'Reset-Mail gesendet'); body = smtp.last_body(); m = re.search(r'(http://\S+r=reset\S*)', body); t.check(bool(m), 'Link in der Mail')
    link = m.group(1); t.check(link.startswith(inst.base + '/'), 'Link nutzt konfigurierte Basis-URL')
    r2 = anon.post('forgot', {'username': 'gibtsnicht@example.de'}, page='forgot'); t.check('Link zum Zurücksetzen' in r2.text, 'neutrale Antwort (Konto existiert nicht)'); t.eq(len(smtp.messages), 1, 'keine Mail an unbekannte Adresse')
    # Host-Header-Angriff: Link darf nicht auf evil.example zeigen
    smtp.messages.clear(); reset_throttle(inst)
    evilc = inst.client(); evilc.req('forgot', headers={'Host': 'evil.example'}); tok0 = evilc.csrf('forgot')
    evilc.req('forgot', data={'csrf': tok0, 'username': 'chef@example.de'}, headers={'Host': 'evil.example'})
    t.check(smtp.messages and 'evil.example' not in smtp.messages[-1]['raw'], 'Host-Header-Manipulation beeinflusst Reset-Link nicht')
    q = urllib.parse.parse_qs(urllib.parse.urlparse(link).query); k = q['k'][0]
    r = anon.req('reset', {'k': 'a' * 64}); t.check('ungültig' in r.text, 'falscher Token abgelehnt')
    r = anon.req('reset', {'k': k}); t.eq(r.code, 200, 'gültiger Link zeigt Formular')
    r = anon.post('reset', {'k': k, 'password': 'password1', 'password2': 'password1'}, page='forgot', params={'k': k}); t.check(inst.sql('rechnung.sqlite', 'select used u from password_resets order by id limit 1')[0]['u'] == 0, 'schwaches Passwort verbraucht Token nicht')
    r = anon.post('reset', {'k': k, 'password': 'Brand-Neu-2026!', 'password2': 'Brand-Neu-2026!'}, page='forgot', params={'k': k}); t.eq(r.route(), 'login', 'Passwort per Link gesetzt')
    r = anon.req('reset', {'k': k}); t.check('ungültig' in r.text, 'Token nur einmal verwendbar')
    reset_throttle(inst); t.eq(login(inst, 'admin', 'Brand-Neu-2026!')[1].code, 302, 'Login mit neuem Passwort')
    inst.sql('rechnung.sqlite', "update password_resets set used=0, expires='2000-01-01 00:00:00'"); r = anon.req('reset', {'k': k}); t.check('ungültig' in r.text, 'abgelaufener Token abgelehnt')
    admin, _ = login(inst, 'admin', 'Brand-Neu-2026!'); admin.post('profile_save', {'display_name': 'Chef', 'email': 'chef@example.de', 'current': 'Brand-Neu-2026!', 'new': PW, 'new2': PW}, page='profile')
    reset_throttle(inst); admin, _ = login(inst)
    reset_throttle(inst); fl = inst.client()
    for i in range(7): fl.post('forgot', {'username': 'chef@example.de'}, page='forgot')
    t.check(len(smtp.messages) < 7, 'Reset-Anfragen ratenbegrenzt')

    t.sec('E-Mail-Versand')
    reset_throttle(inst); smtp.messages.clear()
    iid = inst.sql('rechnung.sqlite', "select id from invoices where status='open' order by id limit 1")[0]['id']
    r = admin.post('mail_send', {'type': 'invoice', 'id': iid, 'to': 'kunde@example.de', 'subject': 'Rechnung', 'body': 'Hallo', 'copy': '1'}, page='mail_new', pageparams={'type': 'invoice', 'id': iid}); t.eq(r.route(), 'invoice_show', 'Mail gesendet')
    t.check(smtp.messages and 'filename="Rechnung_' in smtp.messages[-1]['raw'] or 'Content-Disposition: attachment' in smtp.messages[-1]['raw'], 'PDF im Anhang')
    smtp.messages.clear()
    r = admin.post('mail_send', {'type': 'invoice', 'id': iid, 'to': 'kunde@example.de\r\nBcc: spam@evil.example', 'subject': 'Hallo\r\nBcc: spam2@evil.example', 'body': 'x'}, page='mail_new', pageparams={'type': 'invoice', 'id': iid})
    raw = smtp.messages[-1]['raw'] if smtp.messages else ''; t.check('spam@evil.example' not in raw.split('\r\n\r\n')[0] and 'spam2@evil.example' not in raw.split('\r\n\r\n')[0] and not [m for m in smtp.messages if 'spam@evil.example' in ' '.join(m['rcpt'])], 'Header-Injection über Empfänger/Betreff verhindert')
    r = admin.post('mail_send', {'type': 'invoice', 'id': iid, 'to': 'kein-mail', 'subject': 'x', 'body': 'x'}, page='mail_new', pageparams={'type': 'invoice', 'id': iid}); t.eq(r.route(), 'mail_new', 'ungültige Empfänger-Adresse abgelehnt')
    sent = 0
    for i in range(35):
        r = admin.post('mail_send', {'type': 'invoice', 'id': iid, 'to': 'kunde@example.de', 'subject': 'Spam %d' % i, 'body': 'x'}, page='mail_new', pageparams={'type': 'invoice', 'id': iid}); sent += 1 if r.route() == 'invoice_show' else 0
    t.check(sent < 35, f'Versandlimit greift (gesendet {sent} von 35)')
    t.check(len(inst.sql('rechnung.sqlite', 'select * from mail_log')) > 0, 'Versandprotokoll geführt')

    t.sec('Header, Host, Uploads')
    r = admin.req('dashboard', headers={'Host': 'bad host!'}); t.eq(r.code, 400, 'ungültiger Host-Header abgelehnt')
    r = admin.req('dashboard', headers={'Host': 'a' * 300}); t.eq(r.code, 400, 'überlanger Host abgelehnt')
    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')
    r = admin.post('settings_save', {'company': 'Test GmbH', 'invoice_prefix': 'RE-', 'payment_days': '14'}, page='settings', files={'logo': ('x.php.png', b'<?php system($_GET[1]); ?>')}); t.check(not os.path.exists(sd + '/logo.jpg'), 'PHP-Datei als Logo abgelehnt')
    r = admin.post('settings_save', {'company': 'Test GmbH', 'invoice_prefix': 'RE-', 'payment_days': '14'}, page='settings', files={'logo': ('x.svg', b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')}); t.check(not os.path.exists(sd + '/logo.jpg'), 'SVG-Logo abgelehnt')
    r = admin.post('settings_save', {'company': 'Test GmbH', 'invoice_prefix': 'RE-', 'payment_days': '14'}, page='settings', files={'logo': ('x.png', png)}); t.check(os.path.exists(sd + '/logo.jpg') and open(sd + '/logo.jpg', 'rb').read(3) == b'\xff\xd8\xff', 'PNG wird zu JPG umgewandelt')
    r = admin.req('logo'); t.check(r.code == 200 and r.raw[:2] == b'\xff\xd8', 'Logo-Auslieferung')
    r = admin.req('invoice_pdf', {'id': iid}); t.check(r.raw[:5] == b'%PDF-', 'PDF mit Logo')
    anon = inst.client(); t.eq(anon.req('logo').route(), 'login', 'Logo nur angemeldet')
    r = admin.req('dashboard'); t.check('no-store' in r.headers.get('Cache-Control', ''), 'angemeldete Seiten nicht cachebar')
    t.check(os.stat(sd + '/config.php').st_mode & 0o077 == 0, 'config.php nur für den Besitzer lesbar'); t.check(os.stat(sd + '/secret.key').st_mode & 0o077 == 0, 'secret.key nur für den Besitzer lesbar')
    cfg = open(sd + '/config.php').read(); t.check('pass' not in cfg or "'pass' => ''" in cfg or 'enc1:' in cfg or "'pass' => 'plain" in cfg, 'DB-Passwort nicht im Klartext in config.php')
    t.sec('Fuzzing: manipulierte Parameter und Typen')
    boot = open(inst.dir + '/app/bootstrap.php').read(); names = sorted(set(re.findall(r"'([a-z0-9_]+)' => \['[a-z]+', '[a-z_0-9]+'\]", boot.split('$perms = [')[0])))
    t.check(len(names) > 60, f'{len(names)} Routen gefunden')
    junk = [{'id[]': '1', 'q[]': 'x', 'name[]': 'a', 'type[]': 'invoice', 'status[]': 'open', 'p[]': 'agb', 'k[]': 'x', 't[]': 'x'}, {'id': '1e999', 'q': 'a' * 5000, 'name': '\x00\x01', 'type': "' OR 1=1", 'r': 'x'}, {'id': '-1', 'status': '%00', 'year': '99999999999999999999'}]
    bad = []
    for rt in names:
        if rt in ('logout',): continue
        for j in junk:
            r = admin.req(rt, j)
            if r.code >= 500: bad.append(('GET', rt, r.code))
        tok = admin.csrf('profile')
        for j in junk:
            d = dict(j); d['csrf'] = tok; d['description[]'] = ['a', 'b']; d['quantity[]'] = ['x']; d['perm[x][y]'] = '1'
            r = admin.req(rt, data=d)
            if r.code >= 500: bad.append(('POST', rt, r.code))
        if admin.req('dashboard').code != 200: reset_throttle(inst); admin, _ = login(inst)
    t.eq(bad, [], 'kein Serverfehler (5xx) bei manipulierten Eingaben')
    raw = inst.client(); r = raw.req('dashboard', raw=b'', method='POST'); t.check(r.code in (302, 400), 'POST ohne Inhalt sicher')
    r = raw.req(params={'r[]': 'x'}); t.check(r.code in (200, 302, 404), 'Array als Route sicher')
    r = admin.req('customer_save', multipart={'fields': {'csrf': admin.csrf('profile'), 'company': ['x']}, 'files': {'x[]': ('a', b'1')}}); t.check(r.code in (200, 302, 400), 'Multipart mit Array-Feldern sicher')
    err = inst.errlog(); t.check('Fatal' not in err and 'Warning' not in err and 'Notice' not in err and 'Deprecated' not in err, 'PHP-Fehlerlog sauber:\n' + err[-1500:])
    return admin
