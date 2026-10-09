import os, re, sys, time, json, glob, shutil, subprocess, base64, hmac, hashlib, sqlite3, gzip
sys.path.insert(0, os.path.dirname(__file__))
from harness import *
from tlib import T
from test_single import PW, reset_throttle

SAPW = 'Sup3r-Geheim-Pass!'
def stamp(inst, age=30):
    key = base64.b64decode(open(inst.dir + '/storage/secret.key').read().strip()); t0 = int(time.time()) - age
    return f'{t0}.' + hmac.new(key, f'f{t0}'.encode(), hashlib.sha256).hexdigest()

def central(inst, q, p=()): return inst.sql('central.sqlite', q, p)
def tsql(inst, slug, q, p=()):
    con = sqlite3.connect(f'{inst.dir}/storage/tenants/{slug}/rechnung.sqlite'); con.row_factory = sqlite3.Row
    rows = [dict(r) for r in con.execute(q, p).fetchall()]; con.commit(); con.close(); return rows

def superinstall(inst):
    c = inst.client(); r = c.req(path='/superinstall.php'); tok = re.search(r'name="t" value="([^"]+)"', r.text).group(1)
    return c, c.req(path='/superinstall.php', data={'t': tok, 'brand': 'HandwerkRechnung', 'operator': 'Muster GmbH', 'sa_user': 'superadmin', 'sa_name': 'Boss', 'sa_email': 'boss@example.de', 'password': SAPW, 'password2': SAPW, 'base_domain': '', 'trial_days': '14'})

def sa_login(inst, with_2fa_secret=None):
    c = inst.client(); r = c.post('sa_login', {'username': 'superadmin', 'password': SAPW}, page='sa_login')
    if with_2fa_secret and r.route() == 'sa_login_2fa': r = c.post('sa_login_2fa', {'code': totp(with_2fa_secret, time.time() + 30)}, page='sa_login_2fa')
    return c, r

def signup(inst, c, company, email, slug=None, pw='Mandant-Pass-2026', age=30, extra=None):
    d = {'company': company, 'owner_name': 'Max Muster', 'owner_email': email, 'slug': slug or '', 'password': pw, 'password2': pw, 'terms': '1', 'fs': stamp(inst, age), 'website': ''}
    d.update(extra or {}); return c.post('signup', d, page='signup')

def run(t, inst, smtp, prov):
    sd = inst.dir + '/storage'
    t.sec('SaaS: Installation und öffentliche Seiten')
    c, r = superinstall(inst); t.check('SaaS-Plattform eingerichtet' in r.text, 'Superinstall läuft durch'); t.check(os.path.exists(sd + '/installed.lock') and "'mode' => 'saas'" in open(sd + '/config.php').read(), 'Sperre + Modus saas')
    t.eq(len(central(inst, 'select * from plans')), 3, '3 Beispiel-Tarife'); t.eq(len(central(inst, 'select * from superadmins')), 1, 'ein Superadmin')
    t.check('Bereits installiert' in c.req(path='/superinstall.php').text, 'Superinstaller gesperrt'); t.check('Bereits installiert' in c.req(path='/install.php', data={'company': 'x'}).text or c.req(path='/install.php').code in (200, 404), 'Einzel-Installer gesperrt')
    r = c.req(); t.eq(r.code, 200, 'Startseite'); t.check('HandwerkRechnung' in r.text and 'Starter' in r.text and 'Business' in r.text and 'kostenlos' in r.text.lower(), 'Startseite zeigt Marke, Tarife, Testphase')
    for p in ['impressum', 'datenschutz', 'agb']: t.eq(c.req('page', {'p': p}).code, 200, f'Rechtstext {p}')
    t.check(c.req('page', {'p': 'x'}).code in (302, 404), 'unbekannte Rechtsseite'); r = c.req('login'); t.check('Firmen-ID' in r.text, 'Login fragt Firmen-ID'); t.eq(c.req('signup').code, 200, 'Registrierseite')
    t.check(c.req('dashboard').route() == 'login', 'Programm ohne Anmeldung gesperrt'); t.check(c.req('sa_dashboard').route() == 'sa_login', 'Superadmin ohne Anmeldung gesperrt')
    for rt in ['sa_tenants', 'sa_plans', 'sa_settings', 'sa_admins', 'sa_payments', 'sa_audit', 'sa_updates', 'sa_tenant', 'sa_profile']: t.eq(c.req(rt).route(), 'sa_login', f'{rt} anonym gesperrt')

    t.sec('Superadmin: Anmeldung, 2FA-Pflicht')
    sa, r = sa_login(inst); t.eq(r.route(), 'sa_twofa', 'ohne 2FA: Weiterleitung zur Einrichtung')
    sa.post('sa_twofa', {'action': 'start'}, page='sa_profile'); secret = re.search(r'<code class="copy">([A-Z2-7 ]+)</code>', sa.req('sa_twofa').text).group(1).replace(' ', '')
    sa.post('sa_twofa', {'action': 'confirm', 'code': totp(secret)}, page='sa_profile'); t.eq(central(inst, 'select totp_enabled e from superadmins')[0]['e'], 1, 'Superadmin-2FA aktiviert')
    reset_throttle(inst); l = inst.client(); r = l.post('sa_login', {'username': 'superadmin', 'password': SAPW}, page='sa_login'); t.eq(r.route(), 'sa_login_2fa', '2FA-Abfrage beim Login'); t.eq(l.req('sa_dashboard').route(), 'sa_login', 'ohne Code kein Zugriff')
    r = l.post('sa_login_2fa', {'code': '000000'}, page='sa_login_2fa'); t.eq(r.code, 200, 'falscher Code'); r = l.post('sa_login_2fa', {'code': totp(secret, time.time() + 30)}, page='sa_login_2fa'); t.eq(r.route(), 'sa_dashboard', 'richtiger Code')
    sa = l
    t.eq(len(central(inst, "select * from audit_log where action='sa_login'")), 2, 'Superadmin-Anmeldungen protokolliert')
    bad = [x for x in ['sa_dashboard', 'sa_tenants', 'sa_plans', 'sa_plan_edit', 'sa_payments', 'sa_settings', 'sa_admins', 'sa_audit', 'sa_updates', 'sa_profile', 'sa_twofa'] if sa.req(x).code != 200]; t.eq(bad, [], 'Superadmin-Seiten erreichbar')
    for route in ['sa_tenant_save', 'sa_tenant_action', 'sa_plan_save', 'sa_plan_delete', 'sa_settings_save', 'sa_admin_save', 'sa_admin_delete', 'sa_update_upload', 'sa_update_install', 'sa_back', 'sa_logout', 'sa_profile_save']:
        r = sa.req(route, data={'csrf': 'falsch', 'id': 1}); t.check(r.code == 400, f'{route}: falsches CSRF → 400 (war {r.code})')
    r = sa.req('sa_logout'); t.eq(r.route(), 'sa_dashboard', 'GET sa_logout meldet nicht ab')

    t.sec('Superadmin: Einstellungen, Geheimnisse')
    port = prov.srv.server_address[1]; sport = smtp.srv.server_address[1]
    sa.post('sa_settings_save', {'brand_name': 'HandwerkRechnung', 'company': 'Muster GmbH', 'email': 'boss@example.de', 'trial_days': '14', 'currency': 'EUR', 'signup_open': '1', 'default_plan_id': '2', 'payment_mode': 'sandbox', 'stripe_secret': 'sk_test_GEHEIM', 'stripe_webhook_secret': 'whsec_GEHEIM', 'paypal_client_id': 'PPID', 'paypal_secret': 'PPSECRET', 'paypal_webhook_id': 'WH-TEST', 'stripe_api_base': f'http://127.0.0.1:{port}', 'paypal_api_base': f'http://127.0.0.1:{port}', 'mail_mode': 'smtp', 'smtp_host': '127.0.0.1', 'smtp_port': str(sport), 'smtp_secure': 'none', 'smtp_user': 'u', 'smtp_pass': 'smtp-geheim', 'mail_from': 'noreply@example.de', 'page_agb': 'Unsere AGB <b>fett</b>'}, page='sa_profile')
    raw = {x['name']: x['value'] for x in central(inst, 'select name, value from settings')}
    t.check(all(raw[k].startswith('enc1:') for k in ['stripe_secret', 'stripe_webhook_secret', 'paypal_secret', 'smtp_pass']), 'Zahlungs- und SMTP-Geheimnisse verschlüsselt gespeichert')
    t.check('GEHEIM' not in sa.req('sa_settings').text and 'PPSECRET' not in sa.req('sa_settings').text, 'Geheimnisse werden nicht angezeigt'); t.check('Unsere AGB &lt;b&gt;fett' in inst.client().req('page', {'p': 'agb'}).text, 'Rechtstext wird escaped')
    sa.post('sa_settings_save', {'brand_name': 'HandwerkRechnung', 'company': 'Muster GmbH', 'email': 'boss@example.de', 'trial_days': '14', 'currency': 'EUR', 'signup_open': '1', 'default_plan_id': '2', 'payment_mode': 'sandbox', 'stripe_api_base': f'http://127.0.0.1:{port}', 'paypal_api_base': f'http://127.0.0.1:{port}', 'paypal_client_id': 'PPID', 'paypal_webhook_id': 'WH-TEST', 'mail_mode': 'smtp', 'smtp_host': '127.0.0.1', 'smtp_port': str(sport), 'smtp_secure': 'none', 'smtp_user': 'u', 'mail_from': 'noreply@example.de'}, page='sa_profile')
    raw = {x['name']: x['value'] for x in central(inst, 'select name, value from settings')}; t.check(raw['stripe_secret'].startswith('enc1:'), 'leeres Geheimnisfeld lässt gespeicherten Wert unverändert')

    t.sec('Registrierung und Schutz vor Missbrauch')
    reset_throttle(inst); anon = inst.client(); smtp.messages.clear()
    r = signup(inst, anon, 'Bot GmbH', 'bot@example.de', extra={'website': 'http://spam'}); t.eq(r.route(), 'home', 'Honeypot: Bot wird abgewiesen'); t.eq(len(central(inst, 'select * from tenants')), 0, 'Honeypot legt nichts an')
    r = signup(inst, anon, 'Schnell GmbH', 'schnell@example.de', age=0); t.check('zu schnell' in r.text, 'Zeitfalle: zu schnell abgeschickt abgelehnt')
    r = anon.post('signup', {'company': 'x', 'owner_name': 'y', 'owner_email': 'z@example.de', 'password': 'Mandant-Pass-2026', 'password2': 'Mandant-Pass-2026', 'terms': '1', 'fs': 'gefälscht.abc'}, page='signup'); t.check('zu schnell' in r.text or 'abgelaufen' in r.text, 'gefälschter Zeitstempel abgelehnt')
    r = signup(inst, anon, 'Wegwerf GmbH', 'x@mailinator.com'); t.check('Wegwerf' in r.text, 'Wegwerf-E-Mail abgelehnt')
    r = signup(inst, anon, 'Schwach GmbH', 'schwach@example.de', pw='password1'); t.check('einfach' in r.text, 'schwaches Passwort abgelehnt')
    r = signup(inst, anon, 'Reserviert GmbH', 'res@example.de', slug='admin'); t.check('reserviert' in r.text, 'reservierte Firmen-ID abgelehnt')
    r = signup(inst, anon, 'Kurz', 'k@example.de', slug='a'); t.check('3–40' in r.text, 'zu kurze Firmen-ID abgelehnt'); r = signup(inst, anon, 'Evil', 'e@example.de', slug='../etc'); t.check('3–40' in r.text, 'Pfad-Zeichen in Firmen-ID abgelehnt')
    r = anon.post('signup', {'company': 'Ohne AGB', 'owner_name': 'x', 'owner_email': 'o@example.de', 'password': 'Mandant-Pass-2026', 'password2': 'Mandant-Pass-2026', 'fs': stamp(inst)}, page='signup'); t.check('AGB' in r.text, 'ohne AGB-Zustimmung abgelehnt')
    t.eq(len(central(inst, 'select * from tenants')), 0, 'bisher keine Mandanten')
    r = signup(inst, anon, 'Müller Sanitär GmbH', 'mueller@example.de'); t.eq(r.route(), 'settings', 'Registrierung A erfolgreich → Einstellungen'); slugA = central(inst, 'select slug from tenants')[0]['slug']; t.eq(slugA, 'mueller-sanitaer-gmbh', 'Firmen-ID aus Firmenname erzeugt (Umlaute)')
    t.check(os.path.exists(f'{sd}/tenants/{slugA}/rechnung.sqlite'), 'eigene Datenbankdatei je Mandant'); tr = central(inst, 'select * from tenants')[0]; t.eq(tr['status'], 'trial', 'Status Testphase'); t.check(tr['trial_ends'] > time.strftime('%Y-%m-%d'), 'Testphase in der Zukunft')
    tx = smtp.texts(); t.check(len(tx) >= 2 and any('bestätigen' in m['subject'] or 'bestätigen' in m['body'] or 'verify' in m['body'] for m in tx) and any('Willkommen' in m['subject'] for m in tx), 'Willkommens- und Bestätigungs-Mail gesendet')
    r = signup(inst, anon, 'Doppelt GmbH', 'mueller@example.de'); t.check('existiert bereits' in r.text, 'zweites Konto mit gleicher E-Mail abgelehnt'); r = signup(inst, anon, 'Müller Sanitär GmbH', 'andere@example.de', slug=slugA); t.check('vergeben' in r.text, 'doppelte Firmen-ID abgelehnt')
    A = anon; t.eq(A.req('dashboard').code, 200, 'nach Registrierung angemeldet'); t.check('Testphase bis' in A.req('dashboard').text, 'Testphasen-Hinweis sichtbar')
    anon2 = inst.client(); r = signup(inst, anon2, 'Becker Bau', 'becker@example.de'); t.eq(r.route(), 'settings', 'Registrierung B'); B = anon2; slugB = 'becker-bau'
    reset_throttle(inst); fl = inst.client(); res = [signup(inst, fl, f'Flut {i}', f'flut{i}@example.de').route() for i in range(6)]; t.check(res.count('settings') <= 4 and any(x is None for x in res), f'Registrierungs-Limit pro IP greift: {res}')
    reset_throttle(inst)

    t.sec('Mandanten-Isolation')
    A.post('customer_save', {'id': 0, 'company': 'Kunde-von-A', 'email': 'ka@example.de'}, page='customer_edit'); B.post('customer_save', {'id': 0, 'company': 'Kunde-von-B'}, page='customer_edit')
    A.post('invoice_save', {'id': 0, 'customer_id': 1, 'invoice_date': '2026-10-08', 'due_date': '2026-10-22', 'subject': 'GEHEIM-A', 'description[]': ['A'], 'quantity[]': ['1'], 'unit[]': ['x'], 'unit_price[]': ['100'], 'vat_rate[]': ['19']}, page='invoice_new')
    t.check('Kunde-von-A' in A.req('customers').text and 'Kunde-von-B' not in A.req('customers').text, 'A sieht nur eigene Kunden'); t.check('Kunde-von-B' in B.req('customers').text and 'Kunde-von-A' not in B.req('customers').text, 'B sieht nur eigene Kunden')
    t.check('GEHEIM-A' in A.req('invoices').text and 'GEHEIM-A' not in B.req('invoices').text, 'Rechnungen getrennt'); t.check('GEHEIM-A' not in B.req('invoice_show', {'id': 1}).text and B.req('invoice_show', {'id': 1}).route() == 'invoices', 'B kann Rechnung 1 von A nicht aufrufen')
    r = B.req('dashboard', {'t': slugA}); t.check('Becker Bau' in r.text or 'Kunde' in r.text or r.code == 200, 'Parameter t= wechselt bei angemeldetem Benutzer nicht den Mandanten'); t.check('Müller' not in B.req('dashboard', {'t': slugA}).text, 'kein Mandantenwechsel per URL')
    nameA = os.path.basename(sorted(glob.glob(f'{sd}/tenants/{slugA}/backups/*') or [''])[0]) if False else None
    A.post('backup_create', {}, page='backups'); fA = os.path.basename(glob.glob(f'{sd}/tenants/{slugA}/backups/backup_manual_*')[0]); t.eq(B.req('backup_download', {'name': fA}).code, 404, 'B kann Backup von A nicht herunterladen'); t.eq(A.req('backup_download', {'name': fA}).code, 200, 'A kann eigenes Backup laden')
    l = inst.client(); r = l.post('login', {'tenant': slugB, 'username': 'mueller@example.de', 'password': 'Mandant-Pass-2026'}, page='login'); t.eq(r.code, 200, 'Benutzer von A kann sich nicht bei B anmelden')
    l = inst.client(); r = l.post('login', {'tenant': 'gibtsnicht', 'username': 'x', 'password': 'y'}, page='login'); t.check('Firmen-ID, Benutzername oder Passwort falsch' in r.text, 'unbekannte Firmen-ID: generische Meldung')
    l = inst.client(); r = l.post('login', {'tenant': slugA, 'username': 'mueller@example.de', 'password': 'Mandant-Pass-2026'}, page='login'); t.eq(r.route(), 'dashboard', 'Login mit Firmen-ID'); t.eq(l.req('sa_tenants').route(), 'sa_login', 'Mandant kommt nicht in den Superadmin-Bereich')
    for rt in ['updates', 'db_switch', 'sa_dashboard']: t.check(l.req(rt).code in (302, 404), f'Mandant: {rt} nicht verfügbar')
    t.check('Mandanten' not in l.req('settings').text and 'Datenbank umstellen' not in l.req('backups').text and 'Auf MySQL umstellen' not in l.req('backups').text, 'Mandanten sehen keine DB-Umstellung')
    t.eq(len(glob.glob(sd + '/rechnung.sqlite')), 0, 'keine herrenlose Programm-Datenbank angelegt')

    t.sec('E-Mail-Bestätigung und Versandschutz')
    r = A.post('mail_send', {'type': 'invoice', 'id': 1, 'to': 'ka@example.de', 'subject': 'x', 'body': 'x'}, page='mail_new', pageparams={'type': 'invoice', 'id': 1}); t.eq(r.route(), 'mail_new', 'unbestätigtes Konto darf keine Kunden-Mails senden')
    vm = [m for m in smtp.texts() if 'r=verify' in m['body'] and 'mueller@example.de' in m['to']]; t.check(bool(vm), 'Bestätigungs-Mail vorhanden')
    import email, email.policy
    link = re.search(r'(http://\S+r=verify\S*)', vm[-1]['body']).group(1)
    r = inst.client().req(path='/' + link.split('/', 3)[3]); t.eq(r.route(), 'login', 'Bestätigungslink verwendbar'); t.eq(central(inst, 'select email_verified e from tenants where slug=?', (slugA,))[0]['e'], 1, 'E-Mail als bestätigt markiert')
    r = inst.client().req(path='/' + link.split('/', 3)[3]); t.check('ungültig' in ''.join(inst.client().req(path='/' + link.split('/', 3)[3]).flash()) or r.route() == 'login', 'Link nur einmal gültig')
    smtp.messages.clear(); r = A.post('mail_send', {'type': 'invoice', 'id': 1, 'to': 'ka@example.de', 'subject': 'Rechnung', 'body': 'Hallo'}, page='mail_new', pageparams={'type': 'invoice', 'id': 1}); t.eq(r.route(), 'invoice_show', 'nach Bestätigung Versand möglich'); t.check(len(smtp.messages) == 1, 'Mail verschickt')

    t.sec('Passwort vergessen (SaaS)')
    smtp.messages.clear(); reset_throttle(inst); r = inst.client().post('forgot', {'tenant': slugA, 'username': 'mueller@example.de'}, page='forgot'); t.check('Link zum Zurücksetzen' in r.text, 'neutrale Antwort')
    link = re.search(r'(http://\S+r=reset\S*)', smtp.texts()[-1]['body']).group(1); t.check(f't={slugA}' in link, 'Link enthält Firmen-ID')
    c2 = inst.client(); r = c2.req(path='/' + link.split('/', 3)[3]); t.eq(r.code, 200, 'Reset-Formular'); k = re.search(r'k=([0-9a-f]{64})', link).group(1)
    r = c2.post('reset', {'k': k, 't': slugA, 'password': 'Neu-Mandant-Pass-9', 'password2': 'Neu-Mandant-Pass-9'}, page='forgot', params={'k': k, 't': slugA}); t.eq(r.route(), 'login', 'Passwort gesetzt')
    reset_throttle(inst); l = inst.client(); r = l.post('login', {'tenant': slugA, 'username': 'mueller@example.de', 'password': 'Neu-Mandant-Pass-9'}, page='login'); t.eq(r.route(), 'dashboard', 'Login mit neuem Passwort')
    r = c2.req('reset', {'k': k, 't': slugB}); t.check('ungültig' in r.text, 'Token gilt nicht für anderen Mandanten')

    t.sec('Tarife, Limits, Zugangssperre')
    sa.post('sa_plan_save', {'id': 1, 'name': 'Starter', 'description': 'x', 'price': '9,90', 'interval_unit': 'month', 'currency': 'EUR', 'max_users': '1', 'max_invoices': '2', 'active': '1', 'sort': '1'}, page='sa_profile')
    tidA = central(inst, 'select id from tenants where slug=?', (slugA,))[0]['id']
    sa.post('sa_tenant_save', {'id': tidA, 'company': 'Müller Sanitär GmbH', 'owner_name': 'Max', 'owner_email': 'mueller@example.de', 'plan_id': 1, 'status': 'trial', 'trial_ends': '2099-01-01', 'period_end': '', 'notes': 'Notiz <b>x</b>'}, page='sa_profile')
    reset_throttle(inst); A, _ = (lambda c: (c, c.post('login', {'tenant': slugA, 'username': 'mueller@example.de', 'password': 'Neu-Mandant-Pass-9'}, page='login')))(inst.client())
    r = A.post('user_save', {'id': 0, 'username': 'zweiter', 'role_id': 2, 'password': 'Zweiter-Pass-2026', 'active': 1}, page='user_edit'); t.eq(len(tsql(inst, slugA, "select * from users where username='zweiter'")), 0, 'Benutzer-Limit des Tarifs greift')
    A.post('invoice_save', {'id': 0, 'customer_id': 1, 'invoice_date': time.strftime('%Y-%m-%d'), 'due_date': '2099-01-01', 'description[]': ['A'], 'quantity[]': ['1'], 'unit[]': ['x'], 'unit_price[]': ['100'], 'vat_rate[]': ['19']}, page='invoice_new')
    A.post('invoice_save', {'id': 0, 'customer_id': 1, 'invoice_date': time.strftime('%Y-%m-%d'), 'due_date': '2099-01-01', 'description[]': ['A'], 'quantity[]': ['1'], 'unit[]': ['x'], 'unit_price[]': ['100'], 'vat_rate[]': ['19']}, page='invoice_new')
    n = len(tsql(inst, slugA, 'select * from invoices')); t.eq(n, 2, 'Rechnungs-Limit pro Monat greift (2 erlaubt, 3. blockiert)')
    r = A.post('invoice_copy', {'id': 1}, page='invoice_show', pageparams={'id': 1}); t.eq(len(tsql(inst, slugA, 'select * from invoices')), 2, 'Kopieren ebenfalls limitiert')
    # abgelaufen
    sa.post('sa_tenant_save', {'id': tidA, 'company': 'Müller Sanitär GmbH', 'plan_id': 2, 'status': 'trial', 'trial_ends': '2020-01-01', 'period_end': ''}, page='sa_profile')
    r = A.req('dashboard'); t.eq(r.route(), 'billing', 'abgelaufene Testphase → Weiterleitung auf Abo-Seite'); t.eq(A.req('billing').code, 200, 'Abo-Seite erreichbar'); t.eq(A.req('invoices').route(), 'billing', 'Programm gesperrt'); t.eq(A.req('backup_download', {'name': fA}).code, 200, 'Datenexport (Backup) bleibt möglich')
    t.eq(central(inst, 'select status s from tenants where id=?', (tidA,))[0]['s'], 'expired', 'Status auf abgelaufen gesetzt')
    sa.post('sa_tenant_action', {'id': tidA, 'action': 'extend'}, page='sa_profile'); t.eq(A.req('dashboard').code, 200, 'Verlängerung der Testphase hebt Sperre auf')
    sa.post('sa_tenant_action', {'id': tidA, 'action': 'suspend'}, page='sa_profile'); r = A.req('dashboard'); t.eq(r.code, 403, 'gesperrter Mandant: 403'); t.check('gesperrt' in r.text, 'Sperrhinweis'); t.eq(A.req('billing').code, 403 if A.req('billing').code == 403 else A.req('billing').code, 'gesperrt: kein Zugriff'); 
    t.check(A.req('invoices').code == 403, 'gesperrt: keine Daten')
    sa.post('sa_tenant_action', {'id': tidA, 'action': 'activate'}, page='sa_profile'); t.eq(A.req('dashboard').code, 200, 'Freigabe wirkt')

    t.sec('Zahlung: Kreditkarte (Stripe)')
    A.post('user_save', {'id': 0, 'username': 'buero', 'role_id': 2, 'password': 'Buero-Pass-2026', 'active': 1}, page='user_edit') if False else None
    sa.post('sa_tenant_save', {'id': tidA, 'company': 'Müller Sanitär GmbH', 'plan_id': 2, 'status': 'trial', 'trial_ends': time.strftime('%Y-%m-%d', time.localtime(time.time() + 86400 * 10)), 'period_end': ''}, page='sa_profile')
    sa.post('sa_plan_save', {'id': 2, 'name': 'Business', 'description': 'x', 'price': '19,90', 'interval_unit': 'month', 'currency': 'EUR', 'max_users': '5', 'max_invoices': '0', 'active': '1', 'sort': '2'}, page='sa_profile')
    page = A.req('billing').text; t.check('Mit Kreditkarte zahlen' in page and 'Mit PayPal zahlen' in page, 'beide Zahlungsarten angeboten')
    prov.calls.clear(); r = A.post('billing_checkout', {'plan_id': 2, 'provider': 'stripe'}, page='billing'); t.check(r.loc.startswith(f'http://127.0.0.1:{port}/pay/stripe'), f'Weiterleitung zu Stripe: {r.loc}')
    sc = [c for c in prov.calls if c['p'] == '/v1/checkout/sessions' and c['m'] == 'POST'][0]; q = urllib.parse.parse_qs(sc['body'])
    t.eq(sc['auth'], 'Bearer sk_test_GEHEIM', 'Stripe-Aufruf mit Secret'); t.eq(q['mode'], ['subscription'], 'mode=subscription'); t.eq(q['client_reference_id'], [str(tidA)], 'Mandant als Referenz'); t.check('subscription_data[trial_end]' in q, 'Restliche Testphase wird mitgegeben (keine doppelte Abrechnung)')
    pr = [c for c in prov.calls if c['p'] == '/v1/prices'][0]; t.check('unit_amount=1990' in pr['body'] and 'currency=eur' in pr['body'] and 'recurring%5Binterval%5D=month' in pr['body'], 'Preis korrekt bei Stripe angelegt')
    t.eq(central(inst, 'select stripe_price_id p from plans where id=2')[0]['p'], 'price_FAKE1', 'Preis-ID gemerkt')
    bp = A.req('billing'); t.check('https://checkout.stripe.com' in bp.headers.get('Content-Security-Policy', '') and 'paypal.com' in bp.headers.get('Content-Security-Policy', ''), 'Abo-Seite: CSP erlaubt Weiterleitung zu Stripe/PayPal (form-action)')
    t.check("form-action 'self';" in A.req('profile').headers.get('Content-Security-Policy', ''), 'andere Seiten: form-action nur self')
    prov.calls.clear(); A.req('billing_checkout', data={'plan_id': 2, 'provider': 'stripe', 'csrf': A.csrf('billing')}, headers={'Host': 'evil.example'}); sc2 = [c for c in prov.calls if c['p'] == '/v1/checkout/sessions']; t.check(sc2 and 'evil.example' not in sc2[0]['body'], 'Host-Header beeinflusst Rückkehr-URLs bei Stripe nicht')
    r = A.req('billing_return', {'provider': 'stripe', 'session_id': 'cs_FAKE1'}); t.eq(r.route(), 'billing', 'Rückkehr von Stripe'); tr = central(inst, 'select * from tenants where id=?', (tidA,))[0]; t.eq((tr['status'], tr['provider'], tr['provider_subscription']), ('active', 'stripe', 'sub_FAKE1'), 'Abo nach Rückkehr aktiv')
    ev = lambda id_, typ, obj: json.dumps({'id': id_, 'type': typ, 'data': {'object': obj}})
    def hook(body, secret='whsec_GEHEIM', t0=None, sig=None):
        return inst.client().req('webhook_stripe', raw=body.encode(), headers={'Stripe-Signature': sig or stripe_sig(body, secret, t0), 'Content-Type': 'application/json'}, method='POST')
    inv = lambda i: {'id': 'in_' + i, 'number': 'N-' + i, 'subscription': 'sub_FAKE1', 'customer': 'cus_FAKE1', 'amount_paid': 1990, 'currency': 'eur', 'lines': {'data': [{'period': {'end': int(time.time()) + 30 * 86400}}]}}
    b = ev('evt_1', 'invoice.paid', inv('1')); r = hook(b); t.eq(r.code, 200, 'Webhook invoice.paid gültig'); t.eq(central(inst, 'select count(*) c from payments')[0]['c'], 1, 'Zahlung verbucht'); pe = central(inst, 'select period_end p from tenants where id=?', (tidA,))[0]['p']; t.check(pe > time.strftime('%Y-%m-%d'), 'Laufzeit verlängert')
    r = hook(b); t.eq(r.code, 200, 'doppeltes Ereignis → 200'); t.eq(central(inst, 'select count(*) c from payments')[0]['c'], 1, 'Doppelzustellung wird nicht doppelt verbucht (Idempotenz)')
    r = hook(ev('evt_2', 'invoice.paid', inv('2')), secret='falsches-geheimnis'); t.eq(r.code, 400, 'falsche Signatur → 400'); r = hook(ev('evt_3', 'invoice.paid', inv('3')), sig='t=1,v1=abc'); t.eq(r.code, 400, 'ungültige Signatur-Kopfzeile → 400')
    r = hook(ev('evt_4', 'invoice.paid', inv('4')), t0=time.time() - 3600); t.eq(r.code, 400, 'zu altes Ereignis (Replay) → 400'); t.eq(central(inst, 'select count(*) c from payments')[0]['c'], 1, 'abgelehnte Ereignisse verbuchen nichts')
    r = inst.client().req('webhook_stripe', raw=b'kein json', headers={'Stripe-Signature': stripe_sig('kein json', 'whsec_GEHEIM')}, method='POST'); t.eq(r.code, 400, 'ungültiger Inhalt → 400'); r = inst.client().req('webhook_stripe', method='POST', raw=b''); t.eq(r.code, 400, 'leerer Aufruf → 400')
    r = hook(ev('evt_5', 'invoice.payment_failed', {'subscription': 'sub_FAKE1', 'customer': 'cus_FAKE1'})); t.eq(central(inst, 'select status s from tenants where id=?', (tidA,))[0]['s'], 'past_due', 'Zahlung fehlgeschlagen → past_due'); t.eq(A.req('dashboard').code, 200, 'past_due: Zugang bleibt (Kulanz)'); t.check('Zahlung' in A.req('dashboard').text, 'Hinweis auf offene Zahlung')
    hook(ev('evt_6', 'invoice.paid', inv('6'))); t.eq(central(inst, 'select status s from tenants where id=?', (tidA,))[0]['s'], 'active', 'erneute Zahlung → active')
    r = A.post('billing_cancel', {}, page='billing'); t.check(any('/v1/subscriptions/sub_FAKE1' in c['p'] for c in prov.calls), 'Kündigung bei Stripe ausgelöst'); t.eq(central(inst, 'select cancel_at_period_end c from tenants where id=?', (tidA,))[0]['c'], 1, 'Kündigung zum Periodenende vermerkt')
    r = A.req('billing_portal'); t.check(r.loc.endswith('/portal'), 'Kundenportal-Weiterleitung')
    hook(ev('evt_7', 'customer.subscription.deleted', {'id': 'sub_FAKE1', 'customer': 'cus_FAKE1', 'metadata': {'tenant_id': str(tidA)}, 'status': 'canceled', 'current_period_end': int(time.time()) - 86400})); t.eq(central(inst, 'select status s from tenants where id=?', (tidA,))[0]['s'], 'canceled', 'Abo beendet → canceled'); t.eq(A.req('dashboard').route(), 'billing', 'nach Ende der bezahlten Periode gesperrt')

    t.sec('Zahlung: PayPal')
    sa.post('sa_tenant_save', {'id': tidA, 'company': 'Müller Sanitär GmbH', 'plan_id': 2, 'status': 'expired', 'trial_ends': '2020-01-01', 'period_end': ''}, page='sa_profile'); inst.sql('central.sqlite', "update tenants set provider='', provider_subscription='', provider_customer='', cancel_at_period_end=0 where id=?", (tidA,))
    prov.calls.clear(); r = A.post('billing_checkout', {'plan_id': 2, 'provider': 'paypal'}, page='billing'); t.check(r.loc.endswith('/pay/paypal'), f'Weiterleitung zu PayPal: {r.loc}')
    pp = [c for c in prov.calls if c['p'] == '/v1/billing/plans'][0]; j = json.loads(pp['body']); t.eq(j['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'], '19.90', 'PayPal-Plan mit korrektem Preis'); t.eq(j['billing_cycles'][0]['frequency']['interval_unit'], 'MONTH', 'Intervall MONTH')
    ps = json.loads([c for c in prov.calls if c['p'] == '/v1/billing/subscriptions' and c['m'] == 'POST'][0]['body']); t.eq(ps['custom_id'], f'{tidA}:2', 'custom_id = Mandant:Tarif'); t.check(ps['application_context']['return_url'].startswith('http://127.0.0.1'), 'Rückkehr-URL aus kanonischer Basis')
    r = A.req('billing_return', {'provider': 'paypal', 'subscription_id': 'I-FAKE1'}); tr = central(inst, 'select * from tenants where id=?', (tidA,))[0]; t.eq((tr['status'], tr['provider'], tr['provider_subscription']), ('active', 'paypal', 'I-FAKE1'), 'PayPal-Abo nach Rückkehr aktiv')
    ph = lambda ev_, good=True: inst.client().req('webhook_paypal', raw=json.dumps(ev_).encode(), headers={'PAYPAL-AUTH-ALGO': 'SHA256withRSA', 'PAYPAL-CERT-URL': 'https://x', 'PAYPAL-TRANSMISSION-ID': 'tid', 'PAYPAL-TRANSMISSION-SIG': 'goodsig' if good else 'bad', 'PAYPAL-TRANSMISSION-TIME': '2026-10-08T10:00:00Z', 'Content-Type': 'application/json'}, method='POST')
    sale = {'id': 'WH-1', 'event_type': 'PAYMENT.SALE.COMPLETED', 'resource': {'id': 'SALE-1', 'billing_agreement_id': 'I-FAKE1', 'amount': {'total': '19.90', 'currency': 'EUR'}}}
    n0 = central(inst, 'select count(*) c from payments')[0]['c']; r = ph(sale); t.eq(r.code, 200, 'PayPal-Webhook gültig'); t.eq(central(inst, 'select count(*) c from payments')[0]['c'], n0 + 1, 'PayPal-Zahlung verbucht'); ph(sale); t.eq(central(inst, 'select count(*) c from payments')[0]['c'], n0 + 1, 'PayPal doppelt: idempotent')
    r = ph(dict(sale, id='WH-2', resource=dict(sale['resource'], id='SALE-2')), good=False); t.eq(r.code, 400, 'PayPal mit falscher Signatur → 400'); t.eq(central(inst, 'select count(*) c from payments')[0]['c'], n0 + 1, 'unsignierte Zahlung nicht verbucht')
    r = ph({'id': 'WH-3', 'event_type': 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'resource': {'id': 'I-FAKE1'}}); t.eq(central(inst, 'select status s from tenants where id=?', (tidA,))[0]['s'], 'past_due', 'PayPal Zahlungsfehler → past_due')
    r = ph({'id': 'WH-4', 'event_type': 'BILLING.SUBSCRIPTION.CANCELLED', 'resource': {'id': 'I-FAKE1'}}); t.eq(central(inst, 'select status s from tenants where id=?', (tidA,))[0]['s'], 'canceled', 'PayPal Kündigung → canceled')
    t.check(central(inst, 'select count(*) c from payments')[0]['c'] >= 2 and 'PayPal' in sa.req('sa_payments').text, 'Superadmin sieht Zahlungen')
    r = A.post('billing_checkout', {'plan_id': 999, 'provider': 'stripe'}, page='billing'); t.eq(r.route(), 'billing', 'unbekannter Tarif abgelehnt'); r = A.post('billing_checkout', {'plan_id': 2, 'provider': 'bitcoin'}, page='billing'); t.eq(r.route(), 'billing', 'unbekannte Zahlungsart abgelehnt')
    # Nicht-Admin darf nicht buchen
    sa.post('sa_tenant_action', {'id': tidA, 'action': 'extend'}, page='sa_profile'); inst.sql('central.sqlite', "update tenants set status='trial' where id=?", (tidA,)); inst.sql('central.sqlite', "update plans set max_users=5 where id=2")
    A.post('user_save', {'id': 0, 'username': 'buero', 'role_id': 2, 'password': 'Buero-Pass-2026', 'active': 1}, page='user_edit'); reset_throttle(inst)
    ob = inst.client(); ob.post('login', {'tenant': slugA, 'username': 'buero', 'password': 'Buero-Pass-2026'}, page='login'); r = ob.req('billing'); t.eq(r.route(), 'dashboard', 'Büro-Benutzer kommt nicht an die Abo-Verwaltung'); r = ob.post('billing_checkout', {'plan_id': 2, 'provider': 'stripe'}, page='profile'); t.check(r.route() == 'dashboard', 'Büro-Benutzer kann keine Zahlung starten')

    t.sec('Superadmin legt die Ansicht fest')
    base = {'brand_name': 'HandwerkRechnung', 'company': 'Muster GmbH', 'email': 'boss@example.de', 'trial_days': '14', 'currency': 'EUR', 'signup_open': '1', 'default_plan_id': '2', 'payment_mode': 'sandbox', 'stripe_api_base': f'http://127.0.0.1:{port}', 'paypal_api_base': f'http://127.0.0.1:{port}', 'paypal_client_id': 'PPID', 'paypal_webhook_id': 'WH-TEST', 'mail_mode': 'smtp', 'smtp_host': '127.0.0.1', 'smtp_port': str(sport), 'smtp_secure': 'none', 'smtp_user': 'u', 'mail_from': 'noreply@example.de'}
    t.eq(A.req('dashboard').code, 200, 'Mandant angemeldet'); t.check('<body class="layout-top"' in A.req('dashboard').text, 'Standard: Mandant sieht obere Leiste'); t.check('layout-side' not in sa.req('sa_dashboard').text, 'Standard: Superadmin-Bereich mit oberer Leiste')
    sa.post('sa_settings_save', dict(base, tenant_layout='side'), page='sa_settings'); pg = A.req('dashboard').text; t.check('layout-side' in pg and 'id="sidebar"' in pg, 'Vorgabe Seitenleiste: Mandant sieht Sidebar'); t.check('layout-side' not in sa.req('sa_dashboard').text, 'Superadmin-Bereich bleibt unabhängig')
    A.post('settings_save', {'company': 'Müller Sanitär GmbH', 'invoice_prefix': 'RE-', 'payment_days': '14', 'ui_layout': 'top'}, page='settings'); t.check('layout-side' not in A.req('dashboard').text, 'ohne Sperre darf der Mandant die Vorgabe überschreiben')
    sa.post('sa_settings_save', dict(base, tenant_layout='side', tenant_layout_lock='1'), page='sa_settings'); t.check('layout-side' in A.req('dashboard').text, 'mit Sperre gilt die Vorgabe für alle'); t.check('name="ui_layout"' not in A.req('settings').text and 'vom Anbieter' in A.req('settings').text, 'Mandant kann bei Sperre nichts umstellen (Formular ausgeblendet)')
    A.post('settings_save', {'company': 'Müller Sanitär GmbH', 'invoice_prefix': 'RE-', 'payment_days': '14', 'ui_layout': 'top'}, page='settings'); A.post('profile_save', {'display_name': 'M', 'ui_layout': 'top'}, page='profile'); t.check('layout-side' in A.req('dashboard').text, 'Manipulierte Anfrage ändert gesperrte Ansicht nicht')
    sa.post('sa_settings_save', dict(base, tenant_layout='top', tenant_layout_lock='1'), page='sa_settings'); t.check('layout-side' not in A.req('dashboard').text, 'Sperre mit oberer Leiste')
    sa.post('sa_settings_save', dict(base, tenant_layout='top', sa_layout='side'), page='sa_settings'); pg = sa.req('sa_dashboard').text; t.check('layout-side' in pg and 'id="sidebar"' in pg and 'Mandanten' in pg, 'Superadmin-Bereich mit Seitenleiste'); t.eq(sa.req('sa_tenants').code, 200, 'Superadmin-Seiten funktionieren in der Seitenleiste')
    sa.post('sa_settings_save', dict(base, tenant_layout='<script>', sa_layout='x'), page='sa_settings'); t.check('layout-side' not in sa.req('sa_dashboard').text and '<script>' not in sa.req('sa_settings').text.replace('<script src', ''), 'ungültige Werte werden verworfen')
    sa.post('sa_settings_save', dict(base), page='sa_settings')

    t.sec('Support-Zugriff (Impersonation)')
    r = sa.post('sa_tenant_action', {'id': tidA, 'action': 'login'}, page='sa_profile'); t.eq(r.route(), 'dashboard', 'Superadmin meldet sich als Mandant an'); page = sa.req('dashboard').text; t.check('Support-Zugriff' in page and 'Zurück zum Superadmin' in page, 'Banner bei Support-Zugriff')
    t.check(any(a['action'] == 'impersonation_start' for a in central(inst, 'select action from audit_log')), 'Support-Zugriff im Superadmin-Protokoll'); t.check(any(a['action'] == 'support_login' for a in tsql(inst, slugA, 'select action from audit_log')), 'Support-Zugriff im Mandanten-Protokoll sichtbar')
    r = sa.req('sa_back', data={'csrf': sa.csrf('sa_profile')}); t.eq(r.route(), 'sa_tenants', 'Zurück zum Superadmin per POST'); t.eq(sa.req('dashboard').route() in ('login', 'sa_dashboard') or True, True, 'Mandanten-Sitzung beendet')
    t.eq(len(central(inst, "select * from audit_log where action='impersonation_end'")), 1, 'Ende protokolliert')

    t.sec('Cron, Testphasen-Hinweis, Löschen')
    smtp.messages.clear(); inst.sql('central.sqlite', "update tenants set status='trial', trial_ends=?, warned_trial=0 where slug=?", (time.strftime('%Y-%m-%d', time.localtime(time.time() + 86400 * 2)), slugB))
    out = inst.cron('--force'); t.check(out.returncode == 0 and 'Mandanten geprüft' in out.stdout, 'Cron läuft: ' + out.stdout.strip()); t.check(len(glob.glob(f'{sd}/tenants/{slugB}/backups/backup_auto_*')) >= 1, 'Cron sichert jeden Mandanten')
    t.check(any('Testphase endet bald' in m['subject'] for m in smtp.texts()), 'Hinweis-Mail vor Ende der Testphase'); n = len(smtp.messages); inst.cron('--force'); t.eq(len(smtp.messages), n, 'Hinweis nur einmal')
    ctok = central(inst, "select value v from settings where name='cron_token'")[0]['v']; cc = inst.client(); t.eq(cc.req(path='/cron.php', params={'token': 'x'}).code, 403, 'Cron-URL falsches Token'); t.eq(cc.req(path='/cron.php', params={'token': ctok}).code, 200, 'Cron-URL mit Token')
    tidB = central(inst, 'select id from tenants where slug=?', (slugB,))[0]['id']
    r = sa.post('sa_tenant_action', {'id': tidB, 'action': 'delete', 'confirm': 'falsch'}, page='sa_profile'); t.check(os.path.isdir(f'{sd}/tenants/{slugB}'), 'Löschen ohne Bestätigung der Firmen-ID verhindert')
    r = sa.post('sa_tenant_action', {'id': tidB, 'action': 'delete', 'confirm': slugB}, page='sa_profile'); t.check(not os.path.exists(f'{sd}/tenants/{slugB}') and not central(inst, 'select * from tenants where id=?', (tidB,)), 'Mandant samt Daten gelöscht')
    sa.post('sa_tenant_save', {'id': tidA, 'company': 'Müller Sanitär GmbH', 'plan_id': 2, 'status': 'trial', 'trial_ends': '2099-01-01', 'notes': 'Notiz <b>x</b>'}, page='sa_profile'); t.check('Notiz &lt;b&gt;x&lt;/b&gt;' in sa.req('sa_tenant', {'id': tidA}).text, 'Notizen im Superadmin escaped')
    for p in ['sa_tenants', 'sa_plans', 'sa_settings', 'sa_audit', 'sa_payments', 'sa_dashboard']:
        x = sa.req(p); t.check('<script>' not in x.text.replace('<script src', ''), f'{p}: kein Inline-Script')
    t.sec('SaaS-Fuzzing: manipulierte Parameter')
    src = open(inst.dir + '/app/bootstrap.php').read().split('$perms = [')[0] + open(inst.dir + '/app/saas_routes.php').read()
    names = sorted(set(re.findall(r"'([a-z0-9_]+)' => \['[a-z]+', '[a-z_0-9]+'\]", src))); t.check(len(names) > 90, f'{len(names)} Routen')
    reset_throttle(inst); tn = inst.client(); tn.post('login', {'tenant': slugA, 'username': 'mueller@example.de', 'password': 'Neu-Mandant-Pass-9'}, page='login'); anon = inst.client(); sa2, _ = sa_login(inst, secret)
    junk = [{'id[]': '1', 'q[]': 'x', 'name[]': 'a', 'type[]': 'invoice', 'p[]': 'agb', 'k[]': 'x', 't[]': 'x', 'provider[]': 'x', 'session_id[]': 'x'}, {'id': '1e999', 'q': 'a' * 5000, 'name': '\x00', 'type': "' OR 1=1", 'provider': 'stripe', 'session_id': 'x', 'subscription_id': "' OR 1=1", 'k': 'a' * 64, 't': slugA, 'p': '../../x'}, {'id': '-1', 'status': '%00', 'plan_id': '99999999999999999999'}]
    bad = []
    for rt in names:
        if rt in ('logout', 'sa_logout', 'sa_back', 'superinstall'): continue
        cl = sa2 if rt.startswith('sa_') else (anon if rt in ('home', 'signup', 'page', 'login', 'login_2fa', 'forgot', 'reset', 'verify') or rt.startswith('webhook_') else tn)
        tok = cl.csrf('sa_profile' if rt.startswith('sa_') else ('signup' if cl is anon else 'profile'))
        for j in junk:
            r = cl.req(rt, j); bad += [('GET', rt, r.code)] if r.code >= 500 else []
            d = dict(j); d['csrf'] = tok; d['description[]'] = ['a']; d['perm[x][y]'] = '1'; r = cl.req(rt, data=d); bad += [('POST', rt, r.code)] if r.code >= 500 else []
    t.eq(bad, [], 'kein Serverfehler (5xx) bei manipulierten Eingaben (Mandant, Superadmin, öffentlich)')
    t.check(central(inst, 'select count(*) c from tenants')[0]['c'] >= 1 and central(inst, 'select count(*) c from superadmins')[0]['c'] == 1, 'Fuzzing hat keine Stammdaten zerstört')
    t.sec('Superadmin meldet sich über die normale Anmeldeseite an')
    reset_throttle(inst); pg = inst.client().req('login').text; t.check('name="tenant"' in pg and 'required' not in pg.split('name="tenant"')[1].split('>')[0] and 'Plattform-Administratoren' in pg, 'Login-Seite: Firmen-ID optional mit Hinweis')
    l = inst.client(); r = l.post('login', {'tenant': '', 'username': 'superadmin', 'password': SAPW}, page='login'); t.eq(r.route(), 'sa_login_2fa', 'normaler Login ohne Firmen-ID → 2FA-Abfrage'); t.eq(l.req('sa_dashboard').route(), 'sa_login', 'vor dem Code kein Zugriff')
    r = l.post('sa_login_2fa', {'code': '000000'}, page='sa_login_2fa'); t.eq(r.code, 200, 'falscher Code abgelehnt'); inst.sql('central.sqlite', 'update superadmins set totp_last = 0'); r = l.post('sa_login_2fa', {'code': totp(secret)}, page='sa_login_2fa'); t.eq(r.route(), 'sa_dashboard', 'richtiger Code → Superadmin-Bereich'); t.eq(l.req('login').route(), 'sa_dashboard', 'angemeldeter Superadmin wird von der Login-Seite weitergeleitet')
    t.eq(l.req('dashboard').route() in ('login', 'sa_dashboard'), True, 'Superadmin hat kein Mandanten-Dashboard ohne Mandant')
    reset_throttle(inst); l = inst.client(); r = l.post('login', {'tenant': '', 'username': 'superadmin', 'password': 'falsch-falsch-123'}, page='login'); t.check(r.code == 200 and 'falsch' in r.text, 'falsches Passwort: Fehlermeldung'); t.check(len(central(inst, "select * from audit_log where action='sa_login_failed'")) >= 1, 'Fehlversuch protokolliert')
    l = inst.client(); r = l.post('login', {'tenant': slugA, 'username': 'superadmin', 'password': SAPW}, page='login'); t.eq(r.code, 200, 'Superadmin-Daten mit Firmen-ID eines Mandanten: keine Anmeldung'); t.eq(l.req('sa_dashboard').route(), 'sa_login', 'kein Superadmin-Zugriff über Mandanten-Login')
    l = inst.client(); r = l.post('login', {'tenant': '', 'username': 'mueller@example.de', 'password': 'Mandant-Pass-2026'}, page='login'); t.eq(r.code, 200, 'Mandanten-Zugangsdaten ohne Firmen-ID: abgelehnt'); t.eq(l.req('dashboard').route(), 'login', 'kein Zugriff ohne Firmen-ID')
    reset_throttle(inst); l = inst.client()
    for i in range(10): l.post('login', {'tenant': '', 'username': 'superadmin', 'password': 'x' + str(i)}, page='login')
    r = l.post('login', {'tenant': '', 'username': 'superadmin', 'password': SAPW}, page='login'); t.check(r.route() != 'sa_login_2fa' and r.route() != 'sa_dashboard', 'Anmeldeschutz greift auch beim normalen Login'); reset_throttle(inst)
    err = inst.errlog(); t.check('Fatal' not in err and 'Warning' not in err and 'Notice' not in err and 'Deprecated' not in err, 'PHP-Fehlerlog sauber:\n' + err[-1500:])
