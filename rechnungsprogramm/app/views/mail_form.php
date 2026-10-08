<div class="head"><h1>E-Mail senden</h1></div>
<form method="post" action="<?= e(url('mail_send')) ?>" class="card">
<?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>">
<p class="muted">Von: <?= e($from ?: '– bitte in den Einstellungen eintragen –') ?></p>
<label>An<input type="email" name="to" value="<?= e($d['to']) ?>" required placeholder="kunde@example.de"></label>
<label>Betreff<input name="subject" value="<?= e($d['subject']) ?>" required></label>
<label>Nachricht<textarea name="body" rows="12"><?= e($d['body']) ?></textarea></label>
<p>📎 <?= e($d['file']) ?></p>
<label class="check"><input type="checkbox" name="copy" value="1"<?= $copy ? ' checked' : '' ?>> Kopie an mich senden</label>
<div class="actions"><button class="btn primary">Jetzt senden</button><a class="btn ghost" href="<?= e(url($d['back'][0], $d['back'][1])) ?>">Abbrechen</a></div>
</form>
