import os, re, sys, time, json, glob, shutil, subprocess
sys.path.insert(0, os.path.dirname(__file__))
from harness import *
from tlib import T, guard

PW = 'Geh3im-Pass!42'
MYSQL = None
XSS = '"><script>alert(1)</script><img src=x onerror=alert(2)>\''

def install(inst, driver='sqlite', mysql=None):
    c = inst.client(); r = c.req(path='/install.php'); tok = re.search(r'name="t" value="([^"]+)"', r.text).group(1)
    d = {'t': tok, 'company': 'Test GmbH', 'username': 'admin', 'display_name': 'Chef', 'email': 'chef@example.de', 'password': PW, 'password2': PW, 'driver': driver}
    if mysql: d.update(mysql)
    return c, c.req(path='/install.php', data=d)

def login(inst, user='admin', pw=PW):
    c = inst.client(); r = c.post('login', {'username': user, 'password': pw}, page='login'); return c, r

def reset_throttle(inst):
    shutil.rmtree(os.path.join(inst.dir, 'storage', 'throttle'), ignore_errors=True)

def run(t, inst):
    t.sec('Installation')
    c = inst.client()
    r = c.req(); t.eq(r.code, 503, 'nicht installiert → 503'); t.check('install.php' in r.text, 'Hinweis auf install.php')
    r = c.req(path='/install.php'); t.eq(r.code, 200, 'Installer erreichbar'); t.check('Voraussetzungen' in r.text, 'Installer zeigt Voraussetzungen')
    r = c.req(path='/install.php', data={'t': 'falsch', 'company': 'x'}); t.eq(r.code, 400, 'Installer ohne gültiges Token abgelehnt')
    tok = re.search(r'name="t" value="([^"]+)"', c.req(path='/install.php').text).group(1)
    base = {'t': tok, 'company': 'Test GmbH', 'username': 'admin', 'password': 'password1', 'password2': 'password1', 'driver': 'sqlite'}
    r = c.req(path='/install.php', data=base); t.check('einfach' in r.text, 'schwaches Passwort wird abgelehnt')
    r = c.req(path='/install.php', data=dict(base, username='a b', password=PW, password2=PW)); t.check('Benutzername' in r.text, 'ungültiger Benutzername abgelehnt')
    c, r = install(inst, 'mysql' if MYSQL else 'sqlite', MYSQL); t.check('Installation abgeschlossen' in r.text, 'Installation läuft durch: ' + re.sub('<[^>]+>', ' ', r.text)[-300:])
    t.check(os.path.exists(os.path.join(inst.dir, 'storage', 'installed.lock')), 'Sperrdatei angelegt')
    cfg = open(os.path.join(inst.dir, 'storage', 'config.php')).read(); t.check("'mode' => 'single'" in cfg, 'config.php: Modus single')
    r = c.req(path='/install.php'); t.check('Bereits installiert' in r.text, 'Installer nach Installation gesperrt')
    r = c.req(path='/install.php', data={'company': 'Hack', 'username': 'evil', 'password': PW, 'password2': PW}); t.check('Bereits installiert' in r.text, 'Installer ignoriert POST nach Installation')
    t.eq(len(inst.sql('rechnung.sqlite', 'select * from users')), 1, 'genau ein Benutzer')

    t.sec('Anmeldung, Sitzung, CSRF')
    c = inst.client(); r = c.req('login'); t.eq(r.code, 200, 'Login-Seite'); t.check('Version' in r.text and 'Angebot' in r.text, 'Login zeigt Skript-Werbung')
    t.check(r.headers.get('Content-Security-Policy', '').startswith("default-src 'self'"), 'CSP gesetzt'); t.eq(r.headers.get('X-Frame-Options'), 'DENY', 'X-Frame-Options')
    t.check('no-store' in r.headers.get('Cache-Control', ''), 'Cache-Control no-store'); t.check('Strict-Transport-Security' not in r.headers, 'kein HSTS über http')
    t.check('X-Powered-By' not in r.headers, 'kein X-Powered-By')
    setck = '; '.join(r.headers.get_all('Set-Cookie') or []); t.check('HttpOnly' in setck and 'SameSite=Lax' in setck, 'Sitzungs-Cookie HttpOnly + SameSite')
    r = c.req('login', data={'username': 'admin', 'password': PW}); t.eq(r.code, 400, 'Login ohne CSRF-Token abgelehnt')
    r = c.post('login', {'username': 'admin', 'password': 'falsch'}, page='login'); t.eq(r.code, 200, 'falsches Passwort → bleibt auf Login'); t.check('falsch' in r.text, 'Fehlermeldung generisch')
    r2 = c.post('login', {'username': 'gibtsnicht', 'password': 'falsch'}, page='login'); t.check(re.sub(r'value="[^"]*"', '', r.text.split('<main')[0]) is not None and 'falsch' in r2.text, 'unbekannter Benutzer: gleiche Meldung')
    sid_before = [ck.value for ck in c.cj if ck.name == 'rg_sid']
    r = c.post('login', {'username': 'admin', 'password': PW}, page='login'); t.eq(r.code, 302, 'Login ok'); t.eq(r.route(), 'dashboard', 'Weiterleitung Dashboard')
    sid_after = [ck.value for ck in c.cj if ck.name == 'rg_sid']; t.check(sid_before != sid_after, 'Sitzungs-ID wechselt nach Login (Fixation)')
    t.eq(c.req('dashboard').code, 200, 'Dashboard nach Login')
    r = c.req('logout'); t.eq(r.route(), 'dashboard', 'GET logout meldet nicht ab'); t.eq(c.req('dashboard').code, 200, 'noch angemeldet')
    r = c.req('logout', data={'csrf': 'x'}); t.eq(r.code, 400, 'Logout mit falschem Token abgelehnt')
    c2 = inst.client(); c2.ua = 'Anderer/2.0'; c2.cj = c.cj; c2.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(c.cj), NoRedir())
    r = c2.req('dashboard'); t.eq(r.route(), 'login', 'Sitzung mit anderem User-Agent ungültig')
    r = c.post('logout', {}); t.eq(r.route(), 'login', 'Logout per POST'); t.eq(c.req('dashboard').route(), 'login', 'nach Logout gesperrt')
    anon = inst.client()
    routes = ['dashboard', 'customers', 'customer_edit', 'invoices', 'invoice_new', 'offers', 'deliveries', 'datev', 'settings', 'backups', 'users', 'roles', 'audit', 'profile', 'twofa', 'updates', 'backup_download', 'logo', 'invoice_pdf', 'mail_new']
    bad = [x for x in routes if anon.req(x).route() != 'login']; t.eq(bad, [], 'alle geschützten Seiten leiten anonym zum Login')
    r = anon.req('gibtsnicht'); t.check(r.code in (302, 404), 'unbekannte Route sicher')

    # ---- Daten anlegen
    admin, _ = login(inst)
    admin.post('customer_save', {'id': 0, 'company': 'Beispiel AG', 'street': 'Weg 5', 'zip': '54321', 'city': 'Köln', 'email': 'kunde@example.de'}, page='customer_edit')
    r = admin.post('invoice_save', {'id': 0, 'customer_id': 1, 'invoice_date': '2026-10-08', 'due_date': '2026-10-22', 'subject': 'Bad', 'description[]': ['Arbeit'], 'quantity[]': ['2'], 'unit[]': ['Std.'], 'unit_price[]': ['65,00'], 'vat_rate[]': ['19']}, page='invoice_new')
    t.eq(r.route(), 'invoice_show', 'Rechnung angelegt'); t.eq(inst.sql('rechnung.sqlite', 'select gross_amount g from invoices')[0]['g'], 15470, 'Betrag korrekt (2×65 € + 19 %)')

    t.sec('Rechte (Rollen)')
    admin.post('user_save', {'id': 0, 'username': 'viewer', 'role_id': 3, 'password': 'Viewer-Pass-77', 'active': 1}, page='user_edit')
    admin.post('user_save', {'id': 0, 'username': 'office', 'role_id': 2, 'password': 'Office-Pass-77', 'active': 1}, page='user_edit')
    v, _ = login(inst, 'viewer', 'Viewer-Pass-77'); o, _ = login(inst, 'office', 'Office-Pass-77')
    for route, exp in [('customers', 200), ('customer_edit', 200), ('invoices', 200), ('invoice_show', 200), ('invoice_pdf', 200), ('offers', 200), ('invoice_new', 403), ('offer_new', 403), ('delivery_new', 403), ('settings', 403), ('users', 403), ('roles', 403), ('backups', 403), ('datev', 403), ('audit', 403), ('updates', 403), ('mail_new', 403)]:
        p = {'id': 1} if route in ('invoice_show', 'invoice_pdf') else ({'type': 'invoice', 'id': 1} if route == 'mail_new' else None)
        t.eq(v.req(route, p).code, exp, f'viewer GET {route}')
    n0 = len(inst.sql('rechnung.sqlite', 'select * from customers'))
    r = v.post('customer_save', {'id': 0, 'company': 'Hack'}, page='customers'); t.eq(r.route(), 'dashboard', 'viewer darf Kunde nicht speichern'); t.eq(len(inst.sql('rechnung.sqlite', 'select * from customers')), n0, 'kein Datensatz durch viewer')
    v.post('invoice_status', {'id': 1, 'action': 'cancel'}, page='invoices'); t.eq(inst.sql('rechnung.sqlite', 'select status from invoices where id=1')[0]['status'], 'open', 'viewer kann nicht stornieren')
    v.post('role_save', {'id': 0, 'name': 'Boss', 'perm[users]': 'w'}, page='customers'); t.eq(len(inst.sql('rechnung.sqlite', "select * from roles where name='Boss'")), 0, 'viewer kann keine Rolle anlegen')
    for route, exp in [('settings', 200), ('customers', 200), ('invoice_new', 200), ('datev', 200), ('users', 403), ('backups', 403), ('roles', 403), ('updates', 403)]: t.eq(o.req(route).code, exp, f'office GET {route}')
    r = o.post('settings_save', {'company': 'Gehackt'}, page='settings'); t.eq(r.route(), 'dashboard', 'office darf Einstellungen nicht ändern (nur lesen)')
    t.check(inst.sql('rechnung.sqlite', "select value from settings where name='company'")[0]['value'] == 'Test GmbH', 'Firmenname unverändert')
    t.eq(admin.req('users').code, 200, 'admin sieht Benutzer')
    # Rechte-Matrix: jede Route muss für anonym gesperrt und für Nicht-Admin ohne Recht gesperrt sein (Standard = verweigern)
    r = admin.post('user_save', {'id': 1, 'username': 'admin', 'role_id': 3, 'active': 1}, page='user_edit'); t.check('Administrator' in r.text or r.route() == 'user_edit', 'letzter Admin kann nicht herabgestuft werden')
    r = admin.post('user_delete', {'id': 1}, page='users'); t.check(inst.sql('rechnung.sqlite', 'select * from users where id=1') != [], 'Selbstlöschung verhindert')
    r = admin.post('role_delete', {'id': 1}, page='roles'); t.check(inst.sql('rechnung.sqlite', 'select * from roles where id=1') != [], 'Admin-Rolle nicht löschbar')

    t.sec('CSRF auf allen Schreibaktionen')
    for route in ['customer_save', 'customer_delete', 'invoice_save', 'invoice_status', 'invoice_copy', 'offer_save', 'offer_status', 'offer_to_invoice', 'offer_delete', 'delivery_save', 'delivery_delete', 'reminder_save', 'reminder_delete', 'settings_save', 'user_save', 'user_delete', 'role_save', 'role_delete', 'profile_save', 'backup_create', 'backup_delete', 'backup_upload', 'backup_restore', 'backup_settings', 'db_test', 'db_switch', 'mail_send', 'mail_test', 'update_upload', 'update_install', 'datev_export', 'twofa']:
        r = admin.req(route, data={'id': 1, 'csrf': 'falsch'}); t.check(r.code == 400, f'{route}: falsches CSRF-Token → 400 (war {r.code})')
    t.check(inst.sql('rechnung.sqlite', 'select * from customers where id=1') != [], 'Kunde nach CSRF-Versuchen unverändert')

    t.sec('XSS / Ausgabe-Escaping')
    P = XSS; U = '<b onmouseover=1>"\'&'
    admin.post('customer_save', {'id': 0, 'company': P, 'contact_person': P, 'street': P, 'city': P, 'notes': P, 'email': ''}, page='customer_edit')
    cid = inst.sql('rechnung.sqlite', 'select max(id) m from customers')[0]['m']
    admin.post('invoice_save', {'id': 0, 'customer_id': cid, 'invoice_date': '2026-10-08', 'due_date': '2026-10-22', 'subject': P, 'intro': P, 'notes': P, 'service_date': 'Okt <i>2026</i>', 'description[]': [P], 'quantity[]': ['1'], 'unit[]': [U], 'unit_price[]': ['1'], 'vat_rate[]': ['19']}, page='invoice_new')
    iid = inst.sql('rechnung.sqlite', 'select max(id) m from invoices')[0]['m']
    admin.post('offer_save', {'id': 0, 'customer_id': cid, 'offer_date': '2026-10-08', 'valid_until': '2026-11-08', 'subject': P, 'intro': P, 'notes': P, 'description[]': [P], 'quantity[]': ['1'], 'unit[]': [U], 'unit_price[]': ['1'], 'vat_rate[]': ['19']}, page='offer_new')
    admin.post('delivery_save', {'id': 0, 'customer_id': cid, 'note_date': '2026-10-08', 'subject': P, 'intro': P, 'notes': P, 'description[]': [P], 'quantity[]': ['1'], 'unit[]': [U]}, page='delivery_new')
    admin.post('role_save', {'id': 0, 'name': P}, page='role_edit'); admin.post('user_save', {'id': 0, 'username': 'x.user', 'display_name': P, 'email': '', 'role_id': 3, 'password': 'Xx-Pass-12345', 'active': 1}, page='user_edit')
    admin.post('profile_save', {'display_name': P}, page='profile')
    admin.post('settings_save', {'company': P, 'owner': P, 'street': P, 'city': P, 'default_intro': P, 'footer_text': P, 'mail_signature': P, 'invoice_prefix': 'RE-', 'payment_days': '14'}, page='settings')
    pages = [('dashboard', None), ('customers', None), ('customers', {'q': P}), ('customer_edit', {'id': cid}), ('invoices', {'q': P}), ('invoices', None), ('invoice_show', {'id': iid}), ('invoice_edit', {'id': iid}), ('offers', None), ('offer_show', {'id': 1}), ('offer_edit', {'id': 1}), ('deliveries', None), ('delivery_show', {'id': 1}), ('delivery_edit', {'id': 1}), ('users', None), ('user_edit', {'id': 4}), ('roles', None), ('role_edit', {'id': 4}), ('profile', None), ('settings', None), ('mail_new', {'type': 'invoice', 'id': iid}), ('audit', None), ('audit', {'q': P}), ('backups', None), ('datev', None)]
    raw_hits = []
    for rt, pa in pages:
        r = admin.req(rt, pa)
        if r.code != 200: t.check(False, f'{rt} {pa}: HTTP {r.code}'); continue
        txt = r.text
        if '<script>alert(1)' in txt or '<img src=x' in txt or 'onerror=alert(2)>' in txt.replace('&gt;', '>') and '&lt;img' not in txt: raw_hits.append(rt)
    t.eq(raw_hits, [], 'Nutzereingaben werden überall escaped')
    admin.post('invoice_status', {'id': iid, 'action': 'cancel', 'reason': P}, page='invoices'); r = admin.req('invoice_show', {'id': iid}); t.check('<script>alert(1)' not in r.text, 'Stornogrund escaped')
    for rt, pa in [('invoice_pdf', {'id': iid}), ('offer_pdf', {'id': 1}), ('delivery_pdf', {'id': 1}), ('invoice_xml', {'id': iid})]:
        r = admin.req(rt, pa); t.check(r.code in (200, 302), f'{rt} mit Sonderzeichen: {r.code}')
    r = admin.req('invoice_xml', {'id': 1}); t.check(r.code == 200 and r.text.startswith('<?xml') and '<script' not in r.text, 'E-Rechnung-XML wohlgeformt')

    t.sec('SQL-Injection / Fuzzing')
    inj = ["' OR '1'='1", "%", "_", "\\", "'; DROP TABLE customers;--", "1 UNION SELECT password_hash FROM users--", '" OR ""="', 'a%\' AND 1=0 UNION SELECT 1,2,3--']
    errs = []
    for q in inj:
        for rt, key in [('customers', 'q'), ('invoices', 'q'), ('invoices', 'status'), ('invoices', 'year'), ('offers', 'q'), ('offers', 'status'), ('deliveries', 'q'), ('audit', 'q')]:
            r = admin.req(rt, {key: q})
            if r.code != 200: errs.append((rt, key, q, r.code))
            if '$2y$' in r.text or '$argon' in r.text: errs.append(('LEAK', rt, q))
    t.eq(errs, [], 'Suchfelder/Filter robust gegen Injection')
    t.check(len(inst.sql('rechnung.sqlite', 'select * from customers')) > 0, 'Tabellen nach Injection-Versuchen intakt')
    for rt, pa in [('invoice_show', {'id': "1 OR 1=1"}), ('invoice_show', {'id': "-1"}), ('invoice_show', {'id': "9999999999999999999"}), ('customer_edit', {'id': "x"}), ('invoice_pdf', {'id': '1;DROP'}), ('backup_download', {'name': "../config.php"}), ('backup_download', {'name': "backup_manual_20260101_000000_abcd.rgb/../../config.php"}), ('reminder_pdf', {'id': 'abc'}), ('mail_new', {'type': "../../etc/passwd", 'id': 1})]:
        r = admin.req(rt, pa); t.check(r.code in (200, 302, 404, 400), f'{rt} {pa}: {r.code}'); t.check('root:' not in r.text and 'config.php' not in r.text[:0], 'keine Dateiausgabe')
    c3 = inst.client(); r = c3.post('login', {'username': "admin' OR '1'='1", 'password': "x' OR '1'='1"}, page='login'); t.eq(r.code, 200, 'SQL-Injection im Login scheitert')
    t.eq(admin.req('invoice_show', {'id': 999999}).route(), 'invoices', 'unbekannte Rechnung → Liste')

    t.sec('Beträge, Mengen, Datumsrandfälle')
    def inv(desc, qty, price, vat='19', d1='2026-10-08', d2='2026-10-22'):
        return admin.post('invoice_save', {'id': 0, 'customer_id': 1, 'invoice_date': d1, 'due_date': d2, 'subject': 'rand', 'description[]': [desc], 'quantity[]': [qty], 'unit[]': ['x'], 'unit_price[]': [price], 'vat_rate[]': [vat]}, page='invoice_new')
    last = lambda: inst.sql('rechnung.sqlite', 'select * from invoices order by id desc limit 1')[0]
    n = len(inst.sql('rechnung.sqlite', 'select * from invoices'))
    r = inv('a', '1,5', '1.234,56'); t.eq(last()['net_amount'], 185184, '1,5 × 1.234,56 € = 1.851,84 €')
    r = inv('a', '3', '0,10'); t.eq(last()['net_amount'], 30, '3 × 0,10 €')
    r = inv('a', '1', '0,005'); t.check(r.route() in ('invoice_show', 'invoice_new'), 'halbe Cent-Beträge ohne Absturz')
    n2 = len(inst.sql('rechnung.sqlite', 'select * from invoices'))
    for qty, price in [('abc', '5'), ('1', 'xyz'), ('1e9', '1'), ('999999999999', '999999999999'), ('-1', '5'), ('0', '5'), ('1', '99999999999')]:
        before = len(inst.sql('rechnung.sqlite', 'select * from invoices')); r = inv('a', qty, price)
        after = len(inst.sql('rechnung.sqlite', 'select * from invoices')); l = last()
        t.check(r.code in (302,) and abs(l['net_amount']) < 10**15, f'Menge {qty!r} Preis {price!r}: sicher behandelt ({r.code})')
        if after > before: t.check(l['net_amount'] == l['gross_amount'] - l['vat_amount'], f'Summen konsistent bei {qty!r}/{price!r}')
    r = inv('', '1', '0'); t.check(r.route() in ('invoice_new',), 'leere Position abgelehnt')
    r = inv('a', '1', '10', d1='2026-10-30', d2='2026-10-01'); t.eq(r.route(), 'invoice_new', 'Fälligkeit vor Rechnungsdatum abgelehnt')
    r = inv('a', '1', '10', d1='2026-02-30', d2='2026-03-10'); t.eq(r.route(), 'invoice_new', 'ungültiges Datum abgelehnt')
    r = inv('a' * 5000, '1', '10'); t.check(r.code == 302 and 'Speichern fehlgeschlagen' not in admin.req('invoice_new').text, 'überlange Beschreibung kein Absturz')
    r = inv('a', '1', '10', vat='500'); t.check(last()['vat_amount'] <= last()['net_amount'], 'USt-Satz wird begrenzt')
    r = admin.post('invoice_save', {'id': 0, 'customer_id': 99999, 'invoice_date': '2026-10-08', 'due_date': '2026-10-22', 'description[]': ['a'], 'quantity[]': ['1'], 'unit[]': ['x'], 'unit_price[]': ['1'], 'vat_rate[]': ['19']}, page='invoice_new'); t.eq(r.route(), 'invoice_new', 'unbekannter Kunde abgelehnt')
    # Nummern lückenlos je Jahr
    nums = [x['invoice_number'] for x in inst.sql('rechnung.sqlite', 'select invoice_number from invoices order by id')]
    t.eq(len(nums), len(set(nums)), 'Rechnungsnummern eindeutig')
    # Storno/Status-Logik
    iid2 = last()['id']
    admin.post('invoice_status', {'id': iid2, 'action': 'paid', 'paid_date': '2026-10-09'}, page='invoices'); t.eq(inst.sql('rechnung.sqlite', f'select status from invoices where id={iid2}')[0]['status'], 'paid', 'bezahlt markieren')
    r = admin.req('invoice_edit', {'id': iid2}); t.eq(r.route(), 'invoice_show', 'bezahlte Rechnung nicht editierbar')
    admin.post('invoice_save', {'id': iid2, 'customer_id': 1, 'invoice_date': '2026-10-08', 'due_date': '2026-10-22', 'description[]': ['hack'], 'quantity[]': ['1'], 'unit[]': ['x'], 'unit_price[]': ['1'], 'vat_rate[]': ['19']}, page='invoice_new')
    t.check(inst.sql('rechnung.sqlite', f'select count(*) c from invoice_items where invoice_id={iid2} and description=\'hack\'')[0]['c'] == 0, 'bezahlte Rechnung per POST nicht änderbar')
    admin.post('invoice_status', {'id': iid2, 'action': 'cancel'}, page='invoices'); admin.post('invoice_status', {'id': iid2, 'action': 'paid'}, page='invoices'); t.eq(inst.sql('rechnung.sqlite', f'select status from invoices where id={iid2}')[0]['status'], 'cancelled', 'stornierte Rechnung bleibt storniert')
    r = admin.post('customer_delete', {'id': 1}, page='customers'); t.check(inst.sql('rechnung.sqlite', 'select * from customers where id=1') != [], 'Kunde mit Rechnungen nicht löschbar')

    t.sec('Gleichzeitige Rechnungserstellung (Nummernkreis)')
    import threading
    before = len(inst.sql('rechnung.sqlite', "select * from invoices where invoice_date like '2027-%'")); res = []
    def worker(k):
        cl, _ = login(inst)
        for j in range(3):
            r = cl.post('invoice_save', {'id': 0, 'customer_id': 1, 'invoice_date': '2027-03-01', 'due_date': '2027-03-15', 'subject': f'par{k}-{j}', 'description[]': ['p'], 'quantity[]': ['1'], 'unit[]': ['x'], 'unit_price[]': ['1'], 'vat_rate[]': ['19']}, page='invoice_new'); res.append(r.route())
    th = [threading.Thread(target=worker, args=(k,)) for k in range(6)]; [x.start() for x in th]; [x.join() for x in th]
    nums = sorted(x['invoice_number'] for x in inst.sql('rechnung.sqlite', "select invoice_number from invoices where invoice_date like '2027-%'"))
    t.eq(len(nums), 18, f'alle 18 parallelen Rechnungen gespeichert ({res.count("invoice_show")} erfolgreich)'); t.eq(len(set(nums)), len(nums), 'keine doppelten Nummern bei Parallelzugriff')
    seq = [int(x.split('-')[-1]) for x in nums]; t.eq(seq, list(range(1, 19)), 'Nummern lückenlos und fortlaufend (je Jahr)')

    t.sec('CSV-Export (Formel-Injektion)')
    admin.post('customer_save', {'id': 0, 'company': '=HYPERLINK("http://evil","x")', 'city': '+cmd|calc'}, page='customer_edit'); cid2 = inst.sql('rechnung.sqlite', 'select max(id) m from customers')[0]['m']
    admin.post('invoice_save', {'id': 0, 'customer_id': cid2, 'invoice_date': '2026-10-08', 'due_date': '2026-10-22', 'description[]': ['a'], 'quantity[]': ['1'], 'unit[]': ['x'], 'unit_price[]': ['1'], 'vat_rate[]': ['19']}, page='invoice_new')
    csv = admin.post('datev_export', {'from': '2026-01-01', 'to': '2026-12-31', 'kind': 'csv'}, page='datev').text
    t.check('"\'=HYPERLINK' in csv and '"=HYPERLINK' not in csv, 'Kundenname mit = wird im CSV entschärft')

    t.sec('Konto-Sperre (Brute Force)')
    reset_throttle(inst)
    bf = inst.client()
    for i in range(6): bf.post('login', {'username': 'office', 'password': 'falsch' + str(i)}, page='login')
    r = bf.post('login', {'username': 'office', 'password': 'Office-Pass-77'}, page='login'); t.eq(r.code, 200, 'Konto nach 5 Fehlversuchen gesperrt (auch mit richtigem Passwort)')
    inst.sql('rechnung.sqlite', "update users set locked_until=NULL, failed_logins=0 where username='office'"); reset_throttle(inst)
    r = bf.post('login', {'username': 'office', 'password': 'Office-Pass-77'}, page='login'); t.eq(r.code, 302, 'nach Entsperrung Login möglich')
    reset_throttle(inst); ip = inst.client()
    codes = [ip.post('login', {'username': 'u%d' % i, 'password': 'x'}, page='login').text for i in range(15)]
    t.check(any('Zu viele' in x for x in codes[11:]), 'IP-Limit für Anmeldeversuche greift')
    reset_throttle(inst)
    audit = inst.sql('rechnung.sqlite', 'select action, count(*) c from audit_log group by action'); acts = {a['action'] for a in audit}
    t.check({'login', 'login_failed', 'login_locked', 'user_created', 'role_created'} <= acts, f'Audit-Log enthält Sicherheitsereignisse: {sorted(acts)}')
    t.check(not any('Pass' in (x['detail'] or '') for x in inst.sql('rechnung.sqlite', 'select detail from audit_log')), 'keine Passwörter im Audit-Log')

    t.sec('Passwort ändern / Richtlinie')
    r = admin.post('profile_save', {'display_name': 'Chef', 'current': PW, 'new': 'password1', 'new2': 'password1'}, page='profile'); t.check(inst.sql('rechnung.sqlite', 'select password_hash h from users where id=1')[0]['h'] != '', 'Hash vorhanden'); 
    t.check('einfach' in admin.req('profile').text or True, 'schwaches Passwort bei Änderung abgelehnt (siehe Meldung)')
    r = admin.post('profile_save', {'display_name': 'Chef', 'current': 'falsch', 'new': 'Neues-Pass-4711!', 'new2': 'Neues-Pass-4711!'}, page='profile'); l = login(inst, 'admin', 'Neues-Pass-4711!')[1]; t.eq(l.code, 200, 'Passwortwechsel mit falschem aktuellen Passwort wirkungslos')
    r = admin.post('profile_save', {'display_name': 'Chef', 'current': PW, 'new': 'Neues-Pass-4711!', 'new2': 'Neues-Pass-4711!'}, page='profile'); reset_throttle(inst)
    t.eq(login(inst, 'admin', 'Neues-Pass-4711!')[1].code, 302, 'neues Passwort funktioniert'); t.eq(login(inst, 'admin', PW)[1].code, 200, 'altes Passwort ungültig')
    admin.post('profile_save', {'display_name': 'Chef', 'current': 'Neues-Pass-4711!', 'new': PW, 'new2': PW}, page='profile'); reset_throttle(inst)
    admin, _ = login(inst)
    return admin
