<div class="head"><h1>Einstellungen</h1></div>
<form method="post" action="<?= e(url('settings_save')) ?>" enctype="multipart/form-data" class="card">
<?= csrf_field() ?>
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
<h2>Logo</h2>
<?php if ($hasLogo): ?><p><img class="logo" src="<?= e(url('logo')) ?>" alt="Logo"><label class="check"><input type="checkbox" name="remove_logo" value="1"> Aktuelles Logo entfernen</label></p><?php endif; ?>
<label>Logo hochladen (<?= $gd ? 'JPG, PNG, GIF oder WebP' : 'nur JPG' ?>)<input type="file" name="logo" accept="image/*"></label>
<div class="actions"><button class="btn primary">Speichern</button></div>
</form>

<div class="card"><h2>Passwort ändern</h2>
<form method="post" action="<?= e(url('password_save')) ?>" class="grid"><?= csrf_field() ?>
<label>Aktuelles Passwort<input type="password" name="current" required autocomplete="current-password"></label><span></span>
<label>Neues Passwort<input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
<label>Wiederholen<input type="password" name="new2" required minlength="8" autocomplete="new-password"></label>
<div><button class="btn">Passwort ändern</button></div></form></div>

<div class="card"><h2>Datensicherung</h2><p class="muted">Lädt die komplette Datenbank (Kunden, Rechnungen, Einstellungen) herunter. Bitte regelmäßig sichern.</p>
<form method="post" action="<?= e(url('backup')) ?>"><?= csrf_field() ?><button class="btn">Datenbank herunterladen</button></form></div>
