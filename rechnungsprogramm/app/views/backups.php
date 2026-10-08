<?php $adminTab = 'backups'; require __DIR__ . '/_admin_tabs.php';
$kinds = ['manual' => 'Manuell', 'auto' => 'Automatisch', 'safety' => 'Sicherheitskopie', 'upload' => 'Hochgeladen'];
$ivl = ['off' => 'Aus', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'monthly' => 'Monatlich']; ?>
<div class="head"><h1>Datenbank &amp; Backups</h1></div>

<div class="card"><h2>Backups</h2>
<div class="actions">
<form method="post" action="<?= e(url('backup_create')) ?>" class="inline"><?= csrf_field() ?><button class="btn primary">Backup jetzt erstellen</button></form>
<form method="post" action="<?= e(url('backup_upload')) ?>" enctype="multipart/form-data" class="inline"><?= csrf_field() ?><input type="file" name="file" accept=".rgb,.gz,.json" required><button class="btn">Hochladen</button></form>
</div>
<?php if (!$gz): ?><p class="msg warn">PHP-zlib fehlt: Backups werden unkomprimiert gespeichert.</p><?php endif; ?>
<?php if ($list): ?>
<div class="tablewrap"><table><thead><tr><th>Datum</th><th>Art</th><th class="r">Größe</th><th></th></tr></thead><tbody>
<?php foreach ($list as $b): ?><tr><td><?= e(date('d.m.Y H:i', $b['ts'])) ?></td><td><?= e($kinds[$b['kind']] ?? $b['kind']) ?></td><td class="r"><?= e(number_format($b['size'] / 1024, 0, ',', '.')) ?> KB</td>
<td class="r">
<a class="btn ghost" href="<?= e(url('backup_download', ['name' => $b['name']])) ?>">Download</a>
<form method="post" action="<?= e(url('backup_restore')) ?>" class="inline" data-confirm="ACHTUNG: Alle aktuellen Daten werden durch dieses Backup ersetzt (vorher wird eine Sicherheitskopie angelegt). Fortfahren?"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><input type="password" name="confirm_password" placeholder="Ihr Passwort" required autocomplete="current-password"><button class="btn">Wiederherstellen</button></form>
<form method="post" action="<?= e(url('backup_delete')) ?>" class="inline" data-confirm="Backup löschen?"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn ghost">Löschen</button></form>
</td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p class="muted">Noch keine Backups vorhanden.</p><?php endif; ?>
<p class="muted">Ein Backup enthält alle Daten (Kunden, Rechnungen, Benutzer, Einstellungen, Logo) in einer Datei und lässt sich in SQLite <em>und</em> MySQL einspielen. Bitte Backups regelmäßig herunterladen und außerhalb des Webspace aufbewahren.</p>
</div>

<form method="post" action="<?= e(url('backup_settings')) ?>" class="card"><?= csrf_field() ?>
<h2>Automatisches Backup</h2>
<div class="grid">
<label>Intervall<select name="backup_interval"><?php foreach ($ivl as $k => $l): ?><option value="<?= e($k) ?>"<?= $interval === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
<label>Maximal aufbewahrte automatische Backups<input type="number" min="1" max="365" name="backup_keep" value="<?= e($keep) ?>"></label>
<?php if (!is_saas()): ?><label class="span2 check"><input type="checkbox" name="backup_pseudo" value="1"<?= $pseudo ? ' checked' : '' ?>> Zusätzlich beim Seitenaufruf prüfen, ob ein Backup fällig ist (falls kein Cronjob möglich)</label><?php endif; ?>
</div>
<p class="muted">Letztes automatisches Backup: <?= $lastAuto ? e(date('d.m.Y H:i', $lastAuto)) : 'noch keines' ?>. Ältere automatische Backups über dem Limit werden gelöscht; manuelle und hochgeladene Backups bleiben erhalten.</p>
<?php if (!is_saas()): ?><h3>Cronjob einrichten</h3>
<p class="muted">Beim Hoster einen Cronjob anlegen (z. B. täglich um 02:00 Uhr). Das Programm entscheidet selbst, ob nach Ihrem Intervall ein Backup fällig ist.</p>
<p>Befehl (Shell-Cron): <code class="copy"><?= e($cronCmd) ?></code></p>
<p>oder URL (Webcron): <code class="copy"><?= e($cronUrl) ?></code></p>
<label class="check"><input type="checkbox" name="new_token" value="1"> Neues Cron-Token erzeugen (alte URL wird ungültig)</label><?php else: ?><p class="muted">Die automatischen Backups führt die Plattform für Sie aus (täglicher Lauf).</p><?php endif; ?>
<div class="actions"><button class="btn primary">Speichern</button></div>
</form>

<?php if (!is_saas()): ?><div class="card"><h2>Datenbank</h2>
<p>Aktiv: <strong><?= $driver === 'mysql' ? 'MySQL / MariaDB' : 'SQLite (Datei storage/rechnung.sqlite)' ?></strong> · <?= (int)$counts['customers'] ?> Kunden, <?= (int)$counts['invoices'] ?> Rechnungen, <?= (int)$counts['users'] ?> Benutzer</p>
<p class="muted">Beim Umstellen werden alle Daten in die andere Datenbank kopiert und geprüft; erst danach wird umgeschaltet. Die bisherige Datenbank bleibt unverändert als Rückfall bestehen. Vorher wird automatisch eine Sicherheitskopie angelegt. Die Umstellung geht in beide Richtungen.</p>
<?php if ($driver === 'sqlite'): ?>
<h3>Auf MySQL / MariaDB umstellen</h3>
<?php if (!$mysqlOk): ?><p class="msg err">Auf diesem Server fehlt die PHP-Erweiterung pdo_mysql.</p><?php else: ?>
<form method="post" class="grid" id="dbform"><?= csrf_field() ?><input type="hidden" name="target" value="mysql">
<label>Server<input name="db_host" value="<?= e($mysql['host']) ?>" required></label>
<label>Port<input name="db_port" value="<?= e((string)$mysql['port']) ?>" inputmode="numeric"></label>
<label>Datenbankname<input name="db_name" value="<?= e($mysql['name']) ?>" required></label>
<label>Benutzer<input name="db_user" value="<?= e($mysql['user']) ?>" required autocomplete="off"></label>
<label class="span2">Passwort der Datenbank<input type="password" name="db_pass" autocomplete="new-password"></label>
<label class="span2 check"><input type="checkbox" name="overwrite" value="1"> Falls in der Ziel-Datenbank schon Programmdaten liegen: überschreiben</label>
<label class="span2">Zur Bestätigung: Ihr Anmelde-Passwort<input type="password" name="confirm_password" autocomplete="current-password"></label>
<div class="actions span2"><button class="btn" formaction="<?= e(url('db_test')) ?>">Verbindung testen</button><button class="btn primary" formaction="<?= e(url('db_switch')) ?>" data-confirm="Alle Daten jetzt nach MySQL kopieren und umstellen?">Auf MySQL umstellen</button></div>
</form>
<?php endif; ?>
<?php else: ?>
<h3>Zurück auf SQLite umstellen</h3>
<?php if (!$sqliteOk): ?><p class="msg err">Auf diesem Server fehlt die PHP-Erweiterung pdo_sqlite.</p><?php else: ?>
<form method="post" class="grid"><?= csrf_field() ?><input type="hidden" name="target" value="sqlite">
<label class="span2 check"><input type="checkbox" name="overwrite" value="1"> Falls die SQLite-Datei noch alte Daten enthält: überschreiben</label>
<label class="span2">Zur Bestätigung: Ihr Anmelde-Passwort<input type="password" name="confirm_password" autocomplete="current-password" required></label>
<div class="actions span2"><button class="btn primary" formaction="<?= e(url('db_switch')) ?>" data-confirm="Alle Daten jetzt nach SQLite kopieren und umstellen?">Auf SQLite umstellen</button></div>
</form>
<?php endif; ?>
<?php endif; ?>
</div>
<?php endif; ?>
