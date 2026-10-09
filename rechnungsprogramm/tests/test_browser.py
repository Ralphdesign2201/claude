"""Echter Browsertest (Chromium) für die Katalog-Auswahl im Beschreibungsfeld."""
import os, re, sys, glob
sys.path.insert(0, os.path.dirname(__file__))
from harness import *
from test_single import PW, login, install

CHROME = (glob.glob('/opt/pw-browsers/chromium-*/chrome-linux/chrome') or [None])[0]

def run(t):
    t.sec('Browser: Katalog-Auswahl im Beschreibungsfeld')
    from playwright.sync_api import sync_playwright
    inst = Instance(8130, 'browser'); inst.start()
    try:
        install(inst); admin, _ = login(inst)
        admin.post('customer_save', {'id': 0, 'company': 'Browser-Kunde'}, page='customer_edit')
        admin.post('catalog_save', {'id': 0, 'kind': 'service', 'number': 'L-1', 'name': 'Arbeitsstunde Geselle', 'description': 'inkl. Kleinmaterial', 'unit': 'Std.', 'price': '65,00', 'vat_rate': '19', 'active': '1'}, page='catalog_edit')
        admin.post('catalog_save', {'id': 0, 'kind': 'article', 'number': 'A-1', 'name': 'Kupferrohr 15 mm', 'unit': 'm', 'price': '8,90', 'vat_rate': '7', 'active': '1'}, page='catalog_edit')
        admin.post('catalog_save', {'id': 0, 'kind': 'article', 'number': 'A-2', 'name': 'Rohr <b>"x"</b> & Co', 'unit': 'Stk.', 'price': '1,00', 'vat_rate': '19', 'active': '1'}, page='catalog_edit')
        base = f'http://127.0.0.1:{inst.port}'
        with sync_playwright() as pw:
            br = pw.chromium.launch(executable_path=CHROME, args=['--no-sandbox']); pg = br.new_page(viewport={'width': 1200, 'height': 900})
            errs = []; pg.on('pageerror', lambda e: errs.append(str(e))); pg.on('response', lambda r: errs.append(f'{r.status} {r.url}') if r.status >= 400 and 'favicon' not in r.url else None)
            pg.goto(base + '/index.php?r=login'); pg.fill('input[name=username]', 'admin'); pg.fill('input[name=password]', PW); pg.click('button.primary'); pg.wait_for_url('**r=dashboard**', timeout=8000)
            pg.goto(base + '/index.php?r=invoice_new')
            ta = pg.locator('textarea[name="description[]"]').first
            ta.click(); t.check(pg.locator('#catdrop').is_visible(), 'Fokus im leeren Beschreibungsfeld zeigt die Auswahl'); t.eq(pg.locator('.catopt').count(), 3, 'alle 3 Einträge sichtbar')
            ta.fill(''); ta.type('rohr'); pg.screenshot(path='/tmp/ui_drop.png'); ta.fill(''); ta.type('kup'); t.eq(pg.locator('.catopt').count(), 1, 'Tippen „kup“ filtert auf Kupferrohr'); t.check('Kupferrohr' in pg.locator('.catopt').first.inner_text(), 'Treffer ist Kupferrohr')
            ta.fill(''); ta.type('L-1'); t.check('Arbeitsstunde' in pg.locator('.catopt').first.inner_text(), 'Suche nach Nummer L-1')
            pg.locator('.catopt').first.click()
            t.eq(ta.input_value(), 'Arbeitsstunde Geselle\ninkl. Kleinmaterial', 'Beschreibung übernommen'); row = pg.locator('tr.item').first
            t.eq((row.locator('.unit').input_value(), row.locator('.price').input_value(), row.locator('.qty').input_value(), row.locator('.vat').input_value()), ('Std.', '65,00', '1', '19'), 'Einheit, Preis, Menge, USt übernommen')
            t.check(not pg.locator('#catdrop').is_visible(), 'Fenster schließt nach Auswahl'); t.check('65,00' in pg.locator('#t-net').inner_text(), 'Summe berechnet')
            # zweite Position per Tastatur
            pg.click('#addrow'); ta2 = pg.locator('textarea[name="description[]"]').nth(1); ta2.type('rohr'); t.eq(pg.locator('.catopt').count(), 2, '„rohr“ findet Kupferrohr und das Sonderzeichen-Rohr')
            pg.keyboard.press('ArrowDown'); pg.keyboard.press('ArrowDown'); pg.keyboard.press('Enter')
            row2 = pg.locator('tr.item').nth(1); t.eq(row2.locator('textarea').input_value(), 'Kupferrohr 15 mm', 'Pfeil runter + Enter wählt aus'); t.eq((row2.locator('.unit').input_value(), row2.locator('.price').input_value(), row2.locator('.vat').input_value()), ('m', '8,90', '7'), 'Artikel-Daten übernommen')
            row2.locator('.qty').fill('10'); t.check('154,00' in pg.locator('#t-net').inner_text(), 'Menge ändern → Summe 65,00 + 89,00')
            # Sonderzeichen werden nicht als HTML interpretiert
            pg.click('#addrow'); ta3 = pg.locator('textarea[name="description[]"]').nth(2); ta3.type('Co'); html = pg.locator('#catdrop').inner_html(); t.check('<b>' not in html and '&lt;b&gt;' in html, 'Sonderzeichen im Namen werden escaped')
            pg.keyboard.press('Escape'); t.check(not pg.locator('#catdrop').is_visible(), 'Esc schließt die Auswahl')
            # Freitext + Enter ohne Auswahl = Zeilenumbruch
            ta3.fill(''); ta3.type('Freier Text'); pg.keyboard.press('Enter'); pg.keyboard.type('zweite Zeile'); t.eq(ta3.input_value(), 'Freier Text\nzweite Zeile', 'Freitext bleibt möglich, Enter = Zeilenumbruch')
            ta3.fill(''); ta3.type('Qwertz'); t.check(not pg.locator('#catdrop').is_visible(), 'ohne Treffer keine Auswahl')
            pg.screenshot(path='/tmp/ui_invoice.png')
            # Speichern
            ta3.fill(''); pg.select_option('select[name=customer_id]', label=re.compile('x') and None) if False else None
            pg.locator('select[name=customer_id]').select_option(index=1); pg.locator('tr.item').nth(2).locator('.price').fill('0,00')
            pg.locator('tr.item').nth(2).locator('textarea').fill('Anfahrt'); pg.click('button.primary:has-text("Speichern")'); pg.wait_for_url('**r=invoice_show**', timeout=8000)
            r = inst.sql('rechnung.sqlite', 'select net_amount n from invoices order by id desc limit 1')[0]; t.eq(r['n'], 6500 + 8900, 'gespeicherte Rechnung: Netto 154,00 €')
            t.eq(inst.sql('rechnung.sqlite', 'select count(*) c from invoice_items')[0]['c'], 3, 'drei Positionen gespeichert')
            # Angebot (mit Preis) und Lieferschein (ohne Preis)
            pg.goto(base + '/index.php?r=offer_new'); a = pg.locator('textarea[name="description[]"]').first; a.click(); a.type('A-1'); pg.locator('.catopt').first.click(); t.eq(pg.locator('tr.item').first.locator('.price').input_value(), '8,90', 'Angebot: Auswahl funktioniert')
            pg.goto(base + '/index.php?r=delivery_new'); d = pg.locator('textarea[name="description[]"]').first; d.click(); t.check('€' not in pg.locator('#catdrop').inner_text(), 'Lieferschein: keine Preise in der Auswahl'); pg.locator('.catopt').first.click(); t.check(d.input_value() != '', 'Lieferschein: Auswahl funktioniert')
            # Seitenleisten-Ansicht + schmale Anzeige
            pg.set_viewport_size({'width': 390, 'height': 800}); pg.goto(base + '/index.php?r=invoice_new'); pg.locator('textarea[name="description[]"]').first.click(); t.check(pg.locator('#catdrop').is_visible(), 'Mobil: Auswahl sichtbar'); pg.screenshot(path='/tmp/ui_mobile.png')
            t.eq([e for e in errs if 'favicon' not in e], [], 'keine JavaScript-Fehler im Browser')
            br.close()
        # Ohne Recht keine Daten
        admin.post('role_save', {'id': 0, 'name': 'Ohne Katalog', 'perm[invoices]': 'w'}, page='role_edit')
    finally: inst.stop()
