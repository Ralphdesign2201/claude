<?php $adminTab = 'settings'; require __DIR__ . '/_admin_tabs.php'; ?>
<div class="head"><h1>Einstellungen</h1></div>
<form method="post" action="<?= e(url('settings_save')) ?>" enctype="multipart/form-data" class="card">
<?= csrf_field() ?>
<h2>Darstellung</h2>
<?php if (layout_locked()): ?><p class="muted">Die Ansicht (<?= platform_layout() === 'side' ? 'Seitenleiste' : 'obere Menüleiste' ?>) wird vom Anbieter für alle Konten vorgegeben.</p><?php else: ?>
<div class="grid"><label class="span2">Standard-Ansicht für alle Benutzer<select name="ui_layout"><?php if (is_saas()): ?><option value=""<?= $s['ui_layout'] === '' ? ' selected' : '' ?>>Vorgabe des Anbieters (<?= platform_layout() === 'side' ? 'Seitenleiste' : 'obere Leiste' ?>)</option><?php endif; ?><option value="top"<?= $s['ui_layout'] === 'top' ? ' selected' : '' ?>>Obere Menüleiste (kompakt)</option><option value="side"<?= $s['ui_layout'] === 'side' ? ' selected' : '' ?>>Seitenleiste mit allen Modulen</option></select><small class="muted">Jeder Benutzer kann sie unter „Mein Konto“ für sich ändern.</small></label></div>
<?php endif; ?>
<h2>Firmendaten</h2>
<div class="grid">
<label class="span2">Firma<input name="company" value="<?= e($s['company']) ?>"></label>
<label class="span2">Inhaber / Geschäftsführer<input name="owner" value="<?= e($s['owner']) ?>"></label>
<label class="span2">Straße und Hausnummer<input name="street" value="<?= e($s['street']) ?>"></label>
<label>PLZ<input name="zip" value="<?= e($s['zip']) ?>"></label><label>Ort<input name="city" value="<?= e($s['city']) ?>"></label>
<label>Telefon<input name="phone" value="<?= e($s['phone']) ?>"></label><label>E-Mail<input type="email" name="email" value="<?= e($s['email']) ?>"></label>
<label class="span2">Website<input name="website" value="<?= e($s['website']) ?>"></label>
</div>
<h2>Steuer &amp; Bank</h2>
<div class="grid">
<label>Steuernummer<input name="tax_number" value="<?= e($s['tax_number']) ?>"></label><label>USt-IdNr.<input name="vat_id" value="<?= e($s['vat_id']) ?>"></label>
<label class="span2">Bank<input name="bank" value="<?= e($s['bank']) ?>"></label>
<label>IBAN<input name="iban" value="<?= e($s['iban']) ?>"></label><label>BIC<input name="bic" value="<?= e($s['bic']) ?>"></label>
<label class="span2 check"><input type="checkbox" name="small_business" value="1"<?= $s['small_business'] === '1' ? ' checked' : '' ?>> Kleinunternehmer nach § 19 UStG (keine Umsatzsteuer auf neuen Rechnungen)</label>
</div>
<h2>Rechnungen</h2>
<div class="grid">
<label class="span2 check"><input type="checkbox" name="zugferd" value="1"<?= $s['zugferd'] === '1' ? ' checked' : '' ?>> E-Rechnungsdaten (ZUGFeRD/Factur-X, EN 16931) in jedes PDF einbetten</label>
<label>Nummern-Präfix<input name="invoice_prefix" value="<?= e($s['invoice_prefix']) ?>"><small class="muted">Ergebnis z. B. <?= e($s['invoice_prefix']) ?><?= e(date('Y')) ?>-0001</small></label>
<label>Angebots-Präfix<input name="offer_prefix" value="<?= e($s['offer_prefix']) ?>"></label>
<label>Lieferschein-Präfix<input name="delivery_prefix" value="<?= e($s['delivery_prefix']) ?>"></label>
<label>Zahlungsziel (Tage)<input type="number" min="0" max="365" name="payment_days" value="<?= e($s['payment_days']) ?>"></label>
<label class="span2">Standard-Einleitung<textarea name="default_intro" rows="2"><?= e($s['default_intro']) ?></textarea></label>
<label class="span2">Fußtext auf der Rechnung<textarea name="footer_text" rows="2"><?= e($s['footer_text']) ?></textarea></label>
</div>
<h2>Mahnwesen</h2>
<div class="grid">
<label>Mahngebühr 1. Mahnung (€)<input name="reminder_fee_2" inputmode="decimal" value="<?= e($s['reminder_fee_2']) ?>" placeholder="0,00"></label>
<label>Mahngebühr 2. Mahnung (€)<input name="reminder_fee_3" inputmode="decimal" value="<?= e($s['reminder_fee_3']) ?>" placeholder="0,00"></label>
<label class="span2">Verzugszins (% p.a., optional)<input name="interest_rate" inputmode="decimal" value="<?= e($s['interest_rate']) ?>" placeholder="leer = keine Zinsen"><small class="muted">Gesetzlich: Basiszinssatz + 5 Prozentpunkte (Verbraucher) bzw. + 9 Punkte (Geschäftskunden). Der aktuelle Basiszinssatz steht bei der Bundesbank.</small></label>
</div>
<h2>E-Mail-Versand</h2>
<div class="grid">
<label>Absender-Adresse<input type="email" name="mail_from" value="<?= e($s['mail_from']) ?>" placeholder="leer = E-Mail der Firmendaten"></label>
<label>Versandart<select name="mail_mode"><option value="mail"<?= $s['mail_mode'] === 'mail' ? ' selected' : '' ?>>PHP mail() des Webspace</option><option value="smtp"<?= $s['mail_mode'] === 'smtp' ? ' selected' : '' ?>>SMTP-Server (empfohlen)</option></select></label>
<label>SMTP-Server<input name="smtp_host" value="<?= e($s['smtp_host']) ?>" placeholder="z. B. smtp.example.de"></label>
<label>Port / Verschlüsselung<span class="inline"><input name="smtp_port" value="<?= e($s['smtp_port']) ?>"><select name="smtp_secure"><option value="tls"<?= $s['smtp_secure'] === 'tls' ? ' selected' : '' ?>>STARTTLS (587)</option><option value="ssl"<?= $s['smtp_secure'] === 'ssl' ? ' selected' : '' ?>>SSL (465)</option><option value="none"<?= $s['smtp_secure'] === 'none' ? ' selected' : '' ?>>keine</option></select></span></label>
<label>SMTP-Benutzer<input name="smtp_user" value="<?= e($s['smtp_user']) ?>" autocomplete="off"></label>
<label>SMTP-Passwort<input type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= $s['has_smtp_pass'] ? '•••••• (gespeichert, leer lassen = behalten)' : '' ?>"></label>
<?php if ($s['has_smtp_pass']): ?><label class="span2 check"><input type="checkbox" name="clear_smtp_pass" value="1"> Gespeichertes SMTP-Passwort löschen</label><?php endif; ?>
<label class="span2 check"><input type="checkbox" name="mail_copy" value="1"<?= $s['mail_copy'] === '1' ? ' checked' : '' ?>> Kopie jeder versendeten E-Mail an die Absender-Adresse</label>
<label class="span2">Signatur<textarea name="mail_signature" rows="3" placeholder="leer = Firmenname und Kontaktdaten"><?= e($s['mail_signature']) ?></textarea></label>
</div>
<p class="muted">Das SMTP-Passwort wird in der Datenbank im Klartext gespeichert (geschützt durch den gesperrten Ordner <code>storage/</code>). Am besten ein eigenes Postfach nur für den Versand verwenden.</p>
<h2>Logo</h2>
<?php if ($hasLogo): ?><p><img class="logo" src="<?= e(url('logo')) ?>" alt="Logo"><label class="check"><input type="checkbox" name="remove_logo" value="1"> Aktuelles Logo entfernen</label></p><?php endif; ?>
<label>Logo hochladen (<?= $gd ? 'JPG, PNG, GIF oder WebP' : 'nur JPG' ?>)<input type="file" name="logo" accept="image/*"></label>
<div class="actions"><button class="btn primary">Speichern</button></div>
</form>

<div class="card"><h2>E-Mail testen</h2><p class="muted">Sendet eine Testnachricht an die Absender-Adresse (erst Einstellungen speichern).</p>
<form method="post" action="<?= e(url('mail_test')) ?>"><?= csrf_field() ?><button class="btn">Testmail senden</button></form></div>
