<div class="head"><h1>Export für den Steuerberater</h1></div>
<form method="post" action="<?= e(url('datev_export')) ?>" class="card">
<?= csrf_field() ?>
<h2>Zeitraum</h2>
<div class="grid"><label>Von<input type="date" name="from" value="<?= e($from) ?>" required></label><label>Bis<input type="date" name="to" value="<?= e($to) ?>" required></label></div>
<h2>DATEV-Konten</h2>
<p class="muted">Voreinstellung: Kontenrahmen SKR03 (4-stellige Sachkonten). Bei SKR04 oder abweichender Kontierung bitte die Konten mit dem Steuerberater abstimmen und hier anpassen.</p>
<div class="grid">
<label>Beraternummer<input name="datev_berater" value="<?= e($cfg['datev_berater']) ?>" inputmode="numeric"></label>
<label>Mandantennummer<input name="datev_mandant" value="<?= e($cfg['datev_mandant']) ?>" inputmode="numeric"></label>
<label>Erlöse 19 %<input name="datev_acc_19" value="<?= e($cfg['datev_acc_19']) ?>" inputmode="numeric"></label>
<label>Erlöse 7 %<input name="datev_acc_7" value="<?= e($cfg['datev_acc_7']) ?>" inputmode="numeric"></label>
<label>Erlöse 0 % / steuerfrei<input name="datev_acc_0" value="<?= e($cfg['datev_acc_0']) ?>" inputmode="numeric"></label>
<label>Erlöse Kleinunternehmer<input name="datev_acc_small" value="<?= e($cfg['datev_acc_small']) ?>" inputmode="numeric"></label>
<label>Bankkonto<input name="datev_bank" value="<?= e($cfg['datev_bank']) ?>" inputmode="numeric"></label>
<label>Debitorenkonto = Basis + Kunden-Nr.<input name="datev_debitor_base" value="<?= e($cfg['datev_debitor_base']) ?>" inputmode="numeric"><small class="muted">Kunde 3 → Konto <?= e((string)((int)$cfg['datev_debitor_base'] + 3)) ?></small></label>
</div>
<div class="actions">
<button class="btn primary" name="kind" value="full">DATEV-Buchungsstapel (Rechnungen + Zahlungseingänge)</button>
<button class="btn" name="kind" value="invoices">DATEV nur Rechnungen</button>
<button class="btn" name="kind" value="csv">Rechnungsliste für Excel (CSV)</button>
</div>
<p class="muted">Gebucht wird je Steuersatz: Debitor an Erlöskonto (Automatikkonto, Umsatzsteuer wird von DATEV berechnet). Stornierte Rechnungen erhalten eine Gegenbuchung am Stornodatum. Die Debitorenkonten müssen beim Steuerberater angelegt sein.</p>
</form>
