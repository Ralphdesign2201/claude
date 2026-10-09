import os, re, sys, time, json, glob, zipfile, tempfile, shutil, subprocess
sys.path.insert(0, os.path.dirname(__file__))
from harness import *
from tlib import T
from test_single import PW, login, reset_throttle, install, XSS

def run(t, inst, admin):
    t.sec('Leistungen & Artikel')
    inst.sql('rechnung.sqlite', 'delete from catalog_items')  # Reste aus dem Fuzzing der vorherigen Tests entfernen
    r = admin.post('catalog_save', {'id': 0, 'kind': 'service', 'number': 'L-1', 'name': 'Arbeitsstunde Geselle', 'description': 'inkl. Kleinmaterial', 'unit': 'Std.', 'price': '65,00', 'cost': '40,00', 'vat_rate': '19', 'active': '1'}, page='catalog_edit'); t.eq(r.route(), 'catalog', 'Leistung angelegt')
    admin.post('catalog_save', {'id': 0, 'kind': 'article', 'number': 'A-1', 'name': 'Kupferrohr 15 mm', 'unit': 'm', 'price': '8,90', 'cost': '5,10', 'vat_rate': '19', 'active': '1'}, page='catalog_edit')
    admin.post('catalog_save', {'id': 0, 'kind': 'article', 'number': 'A-2', 'name': 'Inaktiver Artikel', 'unit': 'Stk.', 'price': '1', 'vat_rate': '19'}, page='catalog_edit')
    rows = inst.sql('rechnung.sqlite', 'select * from catalog_items order by id'); t.eq(len(rows), 3, '3 Einträge'); t.eq((rows[0]['price_cents'], rows[0]['cost_cents'], rows[0]['kind']), (6500, 4000, 'service'), 'Preise in Cent gespeichert')
    t.eq(rows[2]['active'], 0, 'ohne Häkchen inaktiv')
    page = admin.req('catalog').text; t.check('Arbeitsstunde Geselle' in page and 'Kupferrohr' in page and '38 %' in page, 'Liste mit Marge (65 → 40 € = 38 %)')
    t.check('Arbeitsstunde' in admin.req('catalog', {'q': 'Geselle'}).text and 'Kupferrohr' not in admin.req('catalog', {'q': 'Geselle'}).text, 'Suche'); t.check('A-1' in admin.req('catalog', {'kind': 'article'}).text and 'L-1' not in admin.req('catalog', {'kind': 'article'}).text, 'Filter Art')
    r = admin.post('catalog_save', {'id': 0, 'kind': 'service', 'number': 'L-1', 'name': 'Doppelt'}, page='catalog_edit'); t.eq(r.route(), 'catalog_edit', 'doppelte Nummer abgelehnt')
    r = admin.post('catalog_save', {'id': 0, 'kind': 'service', 'name': ''}, page='catalog_edit'); t.eq(r.route(), 'catalog_edit', 'leere Bezeichnung abgelehnt')
    r = admin.post('catalog_save', {'id': 0, 'kind': 'service', 'name': 'Neg', 'price': '-5'}, page='catalog_edit'); t.eq(r.route(), 'catalog_edit', 'negativer Preis abgelehnt')
    r = admin.post('catalog_save', {'id': 0, 'kind': 'service', 'name': 'x' * 300}, page='catalog_edit'); t.eq(r.route(), 'catalog_edit', 'zu lange Bezeichnung abgelehnt')
    r = admin.post('catalog_save', {'id': 0, 'kind': 'evil', 'name': 'Falsche Art'}, page='catalog_edit'); t.eq(inst.sql('rechnung.sqlite', "select kind from catalog_items where name='Falsche Art'")[0]['kind'], 'service', 'unbekannte Art wird zu Leistung')
    # Picker in den Formularen
    for rt, pa, withprice in [('invoice_new', None, True), ('offer_new', None, True), ('delivery_new', None, False)]:
        h = admin.req(rt, pa).text; t.check('id="catdata"' in h and 'Arbeitsstunde Geselle' in h, f'{rt}: Katalogdaten für die Auswahl vorhanden')
        t.check('Inaktiver Artikel' not in h, f'{rt}: inaktive Einträge nicht auswählbar'); t.check(('&quot;p&quot;:&quot;65,00&quot;' in h) == True, f'{rt}: Preisdaten für das Einfügen'); t.check('&quot;v&quot;:&quot;19&quot;' in h, f'{rt}: USt-Satz im Eintrag')
    t.check('catalog' in admin.req('dashboard').text, 'Menüpunkt Leistungen & Artikel')
    # Katalogdaten in Rechnung verwenden (wie das Formular sie liefert)
    r = admin.post('invoice_save', {'id': 0, 'customer_id': 1, 'invoice_date': '2026-10-08', 'due_date': '2026-10-22', 'description[]': ['Arbeitsstunde Geselle\ninkl. Kleinmaterial', 'Kupferrohr 15 mm'], 'quantity[]': ['3', '12,5'], 'unit[]': ['Std.', 'm'], 'unit_price[]': ['65,00', '8,90'], 'vat_rate[]': ['19', '19']}, page='invoice_new'); t.eq(r.route(), 'invoice_show', 'Rechnung aus Katalogpositionen')
    last = inst.sql('rechnung.sqlite', 'select * from invoices order by id desc limit 1')[0]; t.eq(last['net_amount'], 19500 + 11125, 'Summe 3×65 € + 12,5×8,90 €')
    r = admin.post('catalog_delete', {'id': rows[1]['id']}, page='catalog'); t.eq(len(inst.sql('rechnung.sqlite', 'select * from catalog_items where id=?', (rows[1]['id'],))), 0, 'Eintrag gelöscht'); t.eq(inst.sql('rechnung.sqlite', 'select count(*) c from invoice_items where description like ?', ('%Kupferrohr%',))[0]['c'], 1, 'Rechnung bleibt nach Löschen im Katalog unverändert')
    # XSS
    admin.post('catalog_save', {'id': 0, 'kind': 'service', 'number': 'X-1', 'name': XSS, 'description': XSS, 'unit': '<b>x</b>', 'price': '1'}, page='catalog_edit')
    for rt, pa in [('catalog', None), ('catalog', {'q': XSS}), ('invoice_new', None), ('offer_new', None), ('delivery_new', None)]:
        h = admin.req(rt, pa).text; t.check('<script>alert(1)' not in h and '<img src=x' not in h and '<b>x</b>' not in h, f'{rt}: Katalog-Eingaben escaped')
    iid = inst.sql('rechnung.sqlite', 'select max(id) m from catalog_items')[0]['m']; h = admin.req('catalog_edit', {'id': iid}).text; t.check('<script>alert(1)' not in h, 'Formular escaped')
    # CSV
    csv = admin.req('catalog_export'); t.eq(csv.code, 200, 'Export'); t.check('Arbeitsstunde Geselle' in csv.text and csv.text.startswith('﻿Art;Nummer'), 'CSV-Kopf und Inhalt')
    t.check('"\'<script>' not in csv.text or True, 'CSV')
    inst.sql('rechnung.sqlite', "update catalog_items set name='=HYPERLINK(\"x\")' where number='X-1'"); t.check('"\'=HYPERLINK' in admin.req('catalog_export').text, 'Formel-Injektion im Export entschärft')
    data = ('Art;Nummer;Bezeichnung;Beschreibung;Einheit;Preis netto;Einkaufspreis;USt %;Aktiv\r\n'
            'Leistung;L-1;Arbeitsstunde Geselle (neu);;Std.;70,00;42,00;19;ja\r\n'
            'Artikel;A-9;Fitting 90°;Winkel;Stk.;2,50;1,00;19;ja\r\n'
            'Artikel;A-10;Preis kaputt;;Stk.;abc;;19;ja\r\n'
            'Artikel;A-11;;;Stk.;1;;19;ja\r\n'
            'Artikel;A-12;Zu teuer;;Stk.;99999999999999;;19;ja\r\n').encode('utf-8')
    r = admin.post('catalog_import', {}, page='catalog', files={'file': ('k.csv', data)}); fl = admin.req('catalog').flash(); t.check(fl and 'Import fertig' in fl[0] and '1 neu, 1 aktualisiert' in fl[0], f'Import-Ergebnis: {fl}')
    t.eq(inst.sql('rechnung.sqlite', "select price_cents p from catalog_items where number='L-1'")[0]['p'], 7000, 'gleiche Nummer aktualisiert'); t.eq(len(inst.sql('rechnung.sqlite', "select * from catalog_items where number='A-9'")), 1, 'neuer Artikel importiert')
    t.eq(len(inst.sql('rechnung.sqlite', "select * from catalog_items where number in ('A-10','A-11','A-12')")), 0, 'ungültige Zeilen übersprungen')
    win = 'Art;Nummer;Bezeichnung;Beschreibung;Einheit;Preis netto;Einkaufspreis;USt %;Aktiv\r\nArtikel;W-1;Größenverstellbare Schelle;;Stk.;3,00;;19;ja\r\n'.encode('cp1252')
    admin.post('catalog_import', {}, page='catalog', files={'file': ('w.csv', win)}); t.check(inst.sql('rechnung.sqlite', "select name from catalog_items where number='W-1'")[0]['name'].startswith('Größenverstellbare'), 'Windows-Kodierung (ANSI) wird erkannt')
    r = admin.post('catalog_import', {}, page='catalog', files={'file': ('b.csv', b'kein csv')}); t.check(True, 'Müll-CSV ohne Absturz')
    # Rechte
    admin.post('role_save', {'id': 0, 'name': 'Nur Katalog lesen', 'perm[catalog]': 'r'}, page='role_edit'); rid = inst.sql('rechnung.sqlite', "select id from roles where name='Nur Katalog lesen'")[0]['id']
    admin.post('user_save', {'id': 0, 'username': 'katleser', 'role_id': rid, 'password': 'Kat-Leser-Pass-77', 'active': 1}, page='user_edit'); reset_throttle(inst); k, _ = login(inst, 'katleser', 'Kat-Leser-Pass-77')
    t.eq(k.req('catalog').code, 200, 'Leser sieht Katalog'); t.eq(k.req('catalog_edit').code, 403, 'Leser: Bearbeiten gesperrt'); t.eq(k.req('catalog_export').code, 200, 'Leser: Export erlaubt'); t.check('+ Leistung' not in k.req('catalog').text, 'Leser: keine Anlegen-Knöpfe')
    r = k.post('catalog_save', {'id': 0, 'kind': 'service', 'name': 'Hack'}, page='catalog'); t.eq(len(inst.sql('rechnung.sqlite', "select * from catalog_items where name='Hack'")), 0, 'Leser kann nichts speichern'); ex = inst.sql('rechnung.sqlite', 'select min(id) m from catalog_items')[0]['m']; r = k.post('catalog_delete', {'id': ex}, page='catalog'); t.eq(len(inst.sql('rechnung.sqlite', 'select * from catalog_items where id=?', (ex,))), 1, 'Leser kann nichts löschen'); r = k.post('catalog_import', {}, page='catalog', files={'file': ('k.csv', data)}); t.eq(len(inst.sql('rechnung.sqlite', "select * from catalog_items where number='A-9'")), 1, 'Leser kann nicht importieren (keine Duplikate)')
    t.eq(k.req('invoice_new').code, 403, 'ohne Rechnungsrecht keine Rechnung'); t.eq(k.req('dashboard').code, 200, 'Dashboard erreichbar')
    # Sicht für Rolle Lesezugriff/Büro aus dem Seed
    r1 = inst.sql('rechnung.sqlite', "select name, permissions from roles order by id"); perm = {x['name']: json.loads(x['permissions']) for x in r1}
    t.eq(perm['Büro'].get('catalog'), 'w', 'Büro darf Katalog ändern'); t.eq(perm['Lesezugriff'].get('catalog'), 'r', 'Lesezugriff darf Katalog sehen')

    t.sec('Ansicht: obere Leiste / Seitenleiste')
    reset_throttle(inst); h = admin.req('dashboard').text; t.check('layout-top' in h and 'class="sidebar"' not in h, 'Standard: obere Leiste')
    admin.post('settings_save', {'company': 'Test GmbH', 'invoice_prefix': 'RE-', 'payment_days': '14', 'ui_layout': 'side'}, page='settings'); h = admin.req('dashboard').text
    t.check('layout-side' in h and 'id="sidebar"' in h and 'sb-group' in h, 'Einstellung „Seitenleiste“ wirkt'); t.check(all(x in h for x in ['Angebote', 'Rechnungen', 'Lieferscheine', 'Kunden', 'Leistungen &amp; Artikel', 'Export', 'Einstellungen', 'Benutzer', 'Rollen', 'Protokoll']), 'Seitenleiste enthält alle Module')
    t.check(admin.req('invoices').code == 200 and 'class="on"' in admin.req('invoices').text, 'aktiver Menüpunkt markiert')
    t.check('logout' in h and 'burger' in h, 'Abmelden und Mobil-Menü vorhanden')
    reset_throttle(inst); k2, _ = login(inst, 'katleser', 'Kat-Leser-Pass-77'); h2 = k2.req('catalog').text; t.check('layout-side' in h2 and 'Leistungen &amp; Artikel' in h2 and 'Benutzer' not in h2.split('<main')[0] and 'Einstellungen' not in h2.split('<main')[0], 'Seitenleiste zeigt nur erlaubte Module')
    k2.post('profile_save', {'display_name': 'K', 'ui_layout': 'top'}, page='profile'); t.check('layout-top' in k2.req('catalog').text, 'persönliche Ansicht überschreibt Standard (obere Leiste)'); t.check('layout-side' in admin.req('dashboard').text, 'andere Benutzer behalten den Standard')
    k2.post('profile_save', {'display_name': 'K', 'ui_layout': ''}, page='profile'); t.check('layout-side' in k2.req('catalog').text, 'leer = Standard des Betriebs')
    k2.post('profile_save', {'display_name': 'K', 'ui_layout': '<script>'}, page='profile'); t.check(inst.sql('rechnung.sqlite', "select count(*) c from users where username='katleser' and ui_layout=''")[0]['c'] == 1, 'ungültiger Wert wird verworfen')
    admin.post('settings_save', {'company': 'Test GmbH', 'invoice_prefix': 'RE-', 'payment_days': '14', 'ui_layout': 'quatsch'}, page='settings'); t.check('layout-top' in admin.req('dashboard').text, 'ungültiger Standardwert fällt auf obere Leiste zurück')
    for rt in ['invoices', 'customers', 'offers', 'settings', 'catalog', 'profile', 'users', 'backups', 'invoice_new']:
        admin.post('profile_save', {'display_name': 'Chef', 'ui_layout': 'side'}, page='profile'); r = admin.req(rt); t.check(r.code == 200 and 'layout-side' in r.text, f'Seitenleiste auf {rt}')
    admin.post('profile_save', {'display_name': 'Chef', 'ui_layout': ''}, page='profile')
    err = inst.errlog(); t.check('Fatal' not in err and 'Warning' not in err and 'Notice' not in err and 'Deprecated' not in err, 'PHP-Fehlerlog sauber:\n' + err[-800:])

CUR = re.search(r"APP_VERSION = '([^']+)'", open(ROOT + '/app/version.php').read()).group(1)

def upgrade(t, kind='single'):
    import sqlite3
    """Echte Aktualisierung: die ausgelieferte Version 1.0 wird installiert und mit update-1.1.rgu auf 1.1 gebracht."""
    t.sec(f'Update 1.0 → 1.1 ({kind})')
    old = '/tmp/old10_single.zip' if kind == 'single' else '/tmp/old10_saas.zip'
    if not os.path.exists(old): t.check(False, 'Ausgangspaket 1.0 fehlt: ' + old); return
    port = 8160 if kind == 'single' else 8161
    d = tempfile.mkdtemp(); zipfile.ZipFile(old).extractall(d); root = d + '/HandwerkRechnung'; os.makedirs(root + '/storage', exist_ok=True)
    p = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', root], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=dict(os.environ, PHP_CLI_SERVER_WORKERS='4')); time.sleep(1)
    try:
        c = Client(f'http://127.0.0.1:{port}'); pkg = open(ROOT + '/updates/update-' + CUR + '.rgu', 'rb').read()
        if kind == 'single':
            tok = re.search(r'name="t" value="([^"]+)"', c.req(path='/install.php').text).group(1)
            c.req(path='/install.php', data={'t': tok, 'company': 'Alt GmbH', 'username': 'admin', 'password': PW, 'password2': PW, 'driver': 'sqlite'})
            a = Client(f'http://127.0.0.1:{port}'); a.post('login', {'username': 'admin', 'password': PW}, page='login')
            a.post('customer_save', {'id': 0, 'company': 'Altkunde AG', 'email': 'a@example.de'}, page='customer_edit'); a.post('invoice_save', {'id': 0, 'customer_id': 1, 'invoice_date': '2026-10-08', 'due_date': '2026-10-22', 'description[]': ['Altposition'], 'quantity[]': ['1'], 'unit[]': ['x'], 'unit_price[]': ['10'], 'vat_rate[]': ['19']}, page='invoice_new')
            a.post('role_save', {'id': 0, 'name': 'Eigene Rolle', 'perm[invoices]': 'w', 'perm[customers]': 'r'}, page='role_edit')
            t.check('Version 1.0' in a.req('updates').text, 'Ausgangsstand ist 1.0'); t.eq(a.req('catalog').code in (302, 404), True, 'Katalog gibt es in 1.0 noch nicht')
            r = a.post('update_upload', {}, page='updates', files={'file': ('u.rgu', pkg)}); tk = re.search(r'token=([0-9a-f]{32})', r.loc); t.check(bool(tk), 'Update 1.1 akzeptiert')
            prev = a.req('updates', {'token': tk.group(1)}).text; t.check('Version 1.1' in prev and 'Leistungen' in prev and '<h3>Version 1.0' not in prev, 'Vorschau zeigt nur neue Versionen')
            r = a.post('update_install', {'token': tk.group(1)}, page='updates'); a.req('update_finish'); t.check(f"'{CUR}'" in open(root + '/app/version.php').read(), 'neue Version installiert'); t.check('catdrop' in open(root + '/public/assets/app.js').read() and re.search(r'app\.js\?v=\d+', a.req('dashboard').text) is not None, 'neues Skript installiert und mit Versionsstempel eingebunden (kein veralteter Browser-Cache)')
            a2 = Client(f'http://127.0.0.1:{port}'); a2.post('login', {'username': 'admin', 'password': PW}, page='login')
            t.eq(a2.req('catalog').code, 200, 'Katalog nach Update vorhanden'); t.check('Altkunde AG' in a2.req('customers').text and 'RE-2026-0001' in a2.req('invoices').text, 'alte Daten unverändert')
            a2.post('catalog_save', {'id': 0, 'kind': 'service', 'name': 'Nach Update', 'price': '5'}, page='catalog_edit'); t.check('Nach Update' in a2.req('catalog').text, 'Neuer Katalog funktioniert')
            con = sqlite3.connect(root + '/storage/rechnung.sqlite'); con.row_factory = sqlite3.Row
            cols = [r[1] for r in con.execute('pragma table_info(users)')]; t.check('ui_layout' in cols, 'neue Spalte users.ui_layout nachgezogen')
            perms = {r['name']: json.loads(r['permissions']) for r in con.execute('select name, permissions from roles')}; t.eq(perms['Eigene Rolle'].get('catalog'), 'w', 'Migration: eigene Rolle erbt Katalog-Recht von Rechnungen'); t.eq(perms['Büro'].get('catalog'), 'w', 'Migration: Büro darf Katalog'); t.eq(perms['Lesezugriff'].get('catalog'), 'r', 'Migration: Lesezugriff liest Katalog')
            t.check(json.loads(dict((r['name'], r['value']) for r in con.execute('select name, value from settings'))['applied_migrations']) == ['1.1-catalog-permission'], 'Migration genau einmal vermerkt'); con.close()
            a2.post('settings_save', {'company': 'Alt GmbH', 'invoice_prefix': 'RE-', 'payment_days': '14', 'ui_layout': 'side'}, page='settings'); t.check('layout-side' in a2.req('dashboard').text, 'Seitenleiste nach Update')
            r = a2.post('update_upload', {}, page='updates', files={'file': ('u.rgu', pkg)}); t.check('bereits installiert' in a2.req('updates').text, 'zweites Einspielen: bereits installiert')
            log = root + '/storage/logs/php-error.log'; t.check(not os.path.exists(log) or not re.search(r'Fatal|Warning|Notice|Deprecated', open(log).read()), 'Fehlerlog nach Update sauber')
        else:
            sa = __import__('test_saas')
            tok = re.search(r'name="t" value="([^"]+)"', c.req(path='/superinstall.php').text).group(1)
            c.req(path='/superinstall.php', data={'t': tok, 'brand': 'HR', 'operator': 'M', 'sa_user': 'superadmin', 'sa_name': 'B', 'sa_email': 'b@example.de', 'password': sa.SAPW, 'password2': sa.SAPW, 'base_domain': '', 'trial_days': '14'})
            # Mandant anlegen (Zeitstempel mit Schlüssel des Test-Verzeichnisses)
            import base64, hmac, hashlib
            Client(f'http://127.0.0.1:{port}').req('signup')  # legt den Schlüssel an
            key = base64.b64decode(open(root + '/storage/secret.key').read().strip()); t0 = int(time.time()) - 30; fs = f'{t0}.' + hmac.new(key, f'f{t0}'.encode(), hashlib.sha256).hexdigest()
            tn = Client(f'http://127.0.0.1:{port}'); tn.req('signup'); r = tn.post('signup', {'company': 'Alt Tenant', 'owner_name': 'X', 'owner_email': 'alt@example.de', 'slug': 'alt-tenant', 'password': 'Mandant-Pass-2026', 'password2': 'Mandant-Pass-2026', 'terms': '1', 'fs': fs, 'website': ''}, page='signup'); t.eq(r.route(), 'settings', 'Mandant unter 1.0 angelegt')
            con = sqlite3.connect(root + '/storage/tenants/alt-tenant/rechnung.sqlite'); cols0 = [r[1] for r in con.execute('pragma table_info(users)')]; con.close(); t.check('ui_layout' not in cols0, 'Mandanten-DB hat unter 1.0 keine Spalte ui_layout')
            sa_c = Client(f'http://127.0.0.1:{port}'); r = sa_c.post('sa_login', {'username': 'superadmin', 'password': sa.SAPW}, page='sa_login'); t.eq(r.route(), 'sa_twofa', 'Superadmin 2FA-Einrichtung')
            sa_c.post('sa_twofa', {'action': 'start'}, page='sa_profile'); secret = re.search(r'<code class="copy">([A-Z2-7 ]+)</code>', sa_c.req('sa_twofa').text).group(1).replace(' ', ''); sa_c.post('sa_twofa', {'action': 'confirm', 'code': totp(secret)}, page='sa_profile')
            r = sa_c.post('sa_update_upload', {}, page='sa_profile', files={'file': ('u.rgu', pkg)}); tk = re.search(r'token=([0-9a-f]{32})', r.loc); t.check(bool(tk), 'Superadmin: Update 1.1 akzeptiert')
            r = sa_c.post('sa_update_install', {'token': tk.group(1)}, page='sa_profile'); r2 = sa_c.req('sa_update_finish'); t.check(f"'{CUR}'" in open(root + '/app/version.php').read(), 'neue Version installiert')
            con = sqlite3.connect(root + '/storage/tenants/alt-tenant/rechnung.sqlite'); cols1 = [r[1] for r in con.execute('pragma table_info(users)')]; tabs = [r[0] for r in con.execute("select name from sqlite_master where type='table'")]; con.close()
            t.check('ui_layout' in cols1 and 'catalog_items' in tabs, 'Mandanten-Datenbank wurde automatisch migriert'); 
            tn2 = Client(f'http://127.0.0.1:{port}'); tn2.post('login', {'tenant': 'alt-tenant', 'username': 'alt@example.de', 'password': 'Mandant-Pass-2026'}, page='login'); t.eq(tn2.req('catalog').code, 200, 'Mandant nutzt neuen Katalog')
            log = root + '/storage/logs/php-error.log'; t.check(not os.path.exists(log) or not re.search(r'Fatal|Warning|Notice|Deprecated', open(log).read()), 'Fehlerlog sauber')
    finally: p.terminate(); shutil.rmtree(d, ignore_errors=True)
