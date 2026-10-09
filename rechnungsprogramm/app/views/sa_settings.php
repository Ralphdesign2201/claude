<?php $sec = fn($k) => $has[$k] ? '•••••• (gespeichert – leer lassen = behalten)' : ''; ?>
<div class="head"><h1>Einstellungen</h1></div>
<form method="post" action="<?= e(url('sa_settings_save')) ?>" class="card"><?= csrf_field() ?>
<h2>Produkt &amp; Registrierung</h2>
<div class="grid">
<label>Produktname<input name="brand_name" value="<?= e($s['brand_name']) ?>"></label><label>Betreiber / Firma<input name="company" value="<?= e($s['company']) ?>"></label>
<label>Kontakt-E-Mail<input type="email" name="email" value="<?= e($s['email']) ?>"></label><label>Basis-Domain (Subdomains, optional)<input name="base_domain" value="<?= e($s['base_domain']) ?>" placeholder="meine-software.de"></label>
<label>Testphase (Tage)<input type="number" min="0" max="365" name="trial_days" value="<?= e($s['trial_days']) ?>"></label><label>Währung<input name="currency" value="<?= e($s['currency']) ?>" maxlength="3"></label>
<label>Standard-Tarif für neue Konten<select name="default_plan_id"><?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $default ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></label><span></span>
<label class="span2 check"><input type="checkbox" name="signup_open" value="1"<?= $s['signup_open'] === '1' ? ' checked' : '' ?>> Neue Registrierungen erlauben</label>
<label class="span2 check"><input type="checkbox" name="sa_require_2fa" value="1"<?= csetting('sa_require_2fa', '1') === '1' ? ' checked' : '' ?>> Zwei-Faktor-Anmeldung für Superadmins verpflichtend (dringend empfohlen)</label>
<label class="span2">Überschrift Startseite (optional)<input name="landing_headline" value="<?= e($s['landing_headline']) ?>"></label>
<label class="span2">Untertext Startseite (optional)<textarea name="landing_sub" rows="2"><?= e($s['landing_sub']) ?></textarea></label>
</div>

<h2>Ansicht</h2>
<div class="grid">
<label>Menü der Kunden-Konten (Mandanten)<select name="tenant_layout"><option value="top"<?= csetting('tenant_layout', 'top') !== 'side' ? ' selected' : '' ?>>Obere Menüleiste</option><option value="side"<?= csetting('tenant_layout', 'top') === 'side' ? ' selected' : '' ?>>Seitenleiste mit Modulen</option></select></label>
<label>Menü im Superadmin-Bereich<select name="sa_layout"><option value="top"<?= csetting('sa_layout', 'top') !== 'side' ? ' selected' : '' ?>>Obere Menüleiste</option><option value="side"<?= csetting('sa_layout', 'top') === 'side' ? ' selected' : '' ?>>Seitenleiste</option></select></label>
<label class="span2 check"><input type="checkbox" name="tenant_layout_lock" value="1"<?= csetting('tenant_layout_lock', '0') === '1' ? ' checked' : '' ?>> Für alle Kunden verbindlich vorgeben (Kunden und deren Benutzer können die Ansicht dann nicht selbst ändern). Ohne Haken ist es nur die Vorgabe, die jeder ändern darf.</label>
</div>

<h2>Zahlungen: Kreditkarte (Stripe)</h2>
<div class="grid">
<label>Geheimer Schlüssel (sk_live_… / sk_test_…)<input type="password" name="stripe_secret" autocomplete="new-password" placeholder="<?= e($sec('stripe_secret')) ?>"></label>
<label>Webhook-Signaturgeheimnis (whsec_…)<input type="password" name="stripe_webhook_secret" autocomplete="new-password" placeholder="<?= e($sec('stripe_webhook_secret')) ?>"></label>
</div>
<p class="muted">Im Stripe-Dashboard unter Entwickler → Webhooks einen Endpunkt anlegen: <code class="copy"><?= e($hook_stripe) ?></code> mit den Ereignissen <em>checkout.session.completed, invoice.paid, invoice.payment_failed, customer.subscription.updated, customer.subscription.deleted</em>. Für das Kundenportal in Stripe die Kundenportal-Funktion aktivieren.</p>

<h2>Zahlungen: PayPal</h2>
<div class="grid">
<label>Modus<select name="payment_mode"><option value="sandbox"<?= $s['payment_mode'] === 'sandbox' ? ' selected' : '' ?>>Sandbox (Test)</option><option value="live"<?= $s['payment_mode'] === 'live' ? ' selected' : '' ?>>Live</option></select></label><span></span>
<label>Client-ID<input name="paypal_client_id" value="<?= e($s['paypal_client_id']) ?>" autocomplete="off"></label>
<label>Secret<input type="password" name="paypal_secret" autocomplete="new-password" placeholder="<?= e($sec('paypal_secret')) ?>"></label>
<label class="span2">Webhook-ID<input name="paypal_webhook_id" value="<?= e($s['paypal_webhook_id']) ?>" autocomplete="off"></label>
</div>
<p class="muted">Im PayPal-Developer-Dashboard einen Webhook anlegen: <code class="copy"><?= e($hook_paypal) ?></code> mit den Ereignissen <em>BILLING.SUBSCRIPTION.ACTIVATED, .CANCELLED, .SUSPENDED, .EXPIRED, .PAYMENT.FAILED und PAYMENT.SALE.COMPLETED</em>; die angezeigte Webhook-ID hier eintragen. Zum Testen zuerst „Sandbox“ verwenden.</p>
<?php foreach (['stripe_secret', 'stripe_webhook_secret', 'paypal_secret'] as $k): if ($has[$k]): ?><label class="check"><input type="checkbox" name="clear_<?= e($k) ?>" value="1"> Gespeicherten Wert „<?= e($k) ?>“ löschen</label><?php endif; endforeach; ?>

<h2>E-Mail-Versand (Willkommens-, Bestätigungs- und Passwort-Mails)</h2>
<div class="grid">
<label>Absender-Adresse<input type="email" name="mail_from" value="<?= e($s['mail_from']) ?>" placeholder="leer = Kontakt-E-Mail"></label>
<label>Versandart<select name="mail_mode"><option value="mail"<?= $s['mail_mode'] === 'mail' ? ' selected' : '' ?>>PHP mail()</option><option value="smtp"<?= $s['mail_mode'] === 'smtp' ? ' selected' : '' ?>>SMTP (empfohlen)</option></select></label>
<label>SMTP-Server<input name="smtp_host" value="<?= e($s['smtp_host']) ?>"></label>
<label>Port / Verschlüsselung<span class="inline"><input name="smtp_port" value="<?= e($s['smtp_port']) ?>"><select name="smtp_secure"><option value="tls"<?= $s['smtp_secure'] === 'tls' ? ' selected' : '' ?>>STARTTLS</option><option value="ssl"<?= $s['smtp_secure'] === 'ssl' ? ' selected' : '' ?>>SSL</option><option value="none"<?= $s['smtp_secure'] === 'none' ? ' selected' : '' ?>>keine</option></select></span></label>
<label>SMTP-Benutzer<input name="smtp_user" value="<?= e($s['smtp_user']) ?>" autocomplete="off"></label>
<label>SMTP-Passwort<input type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= e($sec('smtp_pass')) ?>"></label>
</div>

<h2>Rechtstexte (öffentlich sichtbar)</h2>
<label>Impressum<textarea name="page_impressum" rows="5"><?= e($s['page_impressum']) ?></textarea></label>
<label>Datenschutzerklärung<textarea name="page_datenschutz" rows="5"><?= e($s['page_datenschutz']) ?></textarea></label>
<label>AGB<textarea name="page_agb" rows="5"><?= e($s['page_agb']) ?></textarea></label>
<p class="muted">Bitte lassen Sie diese Texte rechtlich prüfen – insbesondere zu Widerruf, Datenverarbeitung im Auftrag (AV-Vertrag) und Umsatzsteuer.</p>

<details><summary>Erweitert</summary><div class="grid">
<label>Stripe API-Basis (nur für Tests)<input name="stripe_api_base" value="<?= e($s['stripe_api_base']) ?>" placeholder="https://api.stripe.com"></label>
<label>PayPal API-Basis (nur für Tests)<input name="paypal_api_base" value="<?= e($s['paypal_api_base']) ?>" placeholder="leer = automatisch"></label>
</div></details>
<div class="actions"><button class="btn primary">Speichern</button></div>
</form>
