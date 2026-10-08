<?php if ($mailLog): ?><div class="card"><h3>Versendete E-Mails</h3><ul class="plain">
<?php foreach ($mailLog as $m): ?><li><?= $m['ok'] ? '✓' : '✗' ?> <?= e(date('d.m.Y H:i', strtotime($m['sent_at'] . ' UTC'))) ?> an <?= e($m['recipient']) ?><?= $m['ok'] ? '' : ' – <span class="muted">' . e($m['error']) . '</span>' ?></li><?php endforeach; ?>
</ul></div><?php endif; ?>
