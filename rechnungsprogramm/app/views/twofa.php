<?php if (!is_saas() || !sa_user() || !str_starts_with($route, 'sa_')) { ?><div class="head"><h1>Zwei-Faktor-Anmeldung</h1></div><?php } else { ?><div class="head"><h1>Zwei-Faktor-Anmeldung</h1></div><?php } ?>
<?php if ($codes): ?>
<div class="card"><h2>Ihre Wiederherstellungscodes</h2><p class="msg warn">Bitte jetzt notieren und sicher aufbewahren – sie werden nur dieses eine Mal angezeigt. Jeder Code funktioniert einmal, falls Sie Ihr Handy verlieren.</p>
<div class="codes"><?php foreach ($codes as $c): ?><code><?= e($c) ?></code><?php endforeach; ?></div></div>
<?php endif; ?>
<?php if ((int)$u['totp_enabled'] === 1): ?>
<div class="card"><p><span class="badge paid">Aktiv</span> Die Anmeldung verlangt zusätzlich einen Code aus Ihrer Authenticator-App. Verbleibende Wiederherstellungscodes: <?= (int)$left ?>.</p>
<form method="post" action="<?= e(url($route)) ?>" class="grid"><?= csrf_field() ?><input type="hidden" name="action" value="regen">
<label>Passwort bestätigen<input type="password" name="password" required autocomplete="current-password"></label><div><button class="btn">Neue Wiederherstellungscodes erzeugen</button></div></form>
<h3>Deaktivieren</h3>
<form method="post" action="<?= e(url($route)) ?>" class="grid" data-confirm="Zwei-Faktor-Anmeldung wirklich ausschalten?"><?= csrf_field() ?><input type="hidden" name="action" value="disable">
<label>Passwort<input type="password" name="password" required autocomplete="current-password"></label><label>Aktueller Code<input name="code" required autocomplete="one-time-code"></label>
<div><button class="btn danger">Deaktivieren</button></div></form></div>
<?php elseif ($new !== ''): ?>
<div class="card"><h2>Schritt 1: In der Authenticator-App einrichten</h2>
<p>Öffnen Sie eine Authenticator-App (z. B. Google Authenticator, Microsoft Authenticator, Aegis, 1Password) und fügen Sie ein Konto mit diesem Schlüssel hinzu (zeitbasiert, 6 Stellen):</p>
<code class="copy"><?= e(trim(chunk_split($new, 4, ' '))) ?></code>
<p class="muted small">Auf dem Smartphone können Sie diesen Link zum direkten Einrichten öffnen: <a href="<?= e($uri) ?>">Konto hinzufügen</a></p>
<h2>Schritt 2: Code bestätigen</h2>
<form method="post" action="<?= e(url($route)) ?>" class="grid"><?= csrf_field() ?><input type="hidden" name="action" value="confirm">
<label>6-stelliger Code<input name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="7"></label><div><button class="btn primary">Aktivieren</button></div></form></div>
<?php else: ?>
<div class="card"><p>Mit der Zwei-Faktor-Anmeldung kann sich niemand allein mit Ihrem Passwort anmelden – auch nicht, wenn es gestohlen wurde. Dringend empfohlen, besonders für Administratoren.</p>
<form method="post" action="<?= e(url($route)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="btn primary">Einrichten</button></form></div>
<?php endif; ?>
