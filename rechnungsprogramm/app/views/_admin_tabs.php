<?php $tabs = array_filter([
  'settings' => [url('settings'), 'Einstellungen', can('settings')],
  'backups' => [url('backups'), 'Datenbank & Backups', can('system')],
  'users' => [url('users'), 'Benutzer', can('users')],
  'roles' => [url('roles'), 'Rollen', can('users')],
], fn($t) => $t[2]); ?>
<div class="tabs"><?php foreach ($tabs as $k => [$href, $label]): ?><a href="<?= e($href) ?>" class="<?= ($adminTab ?? '') === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?></div>
