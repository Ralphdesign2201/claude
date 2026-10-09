<?php
declare(strict_types=1);

function users_index(): void {
    $rows = db()->query('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY LOWER(u.username)')->fetchAll();
    render('users', ['users' => $rows], 'Benutzer');
}

function users_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $u = ['id' => 0, 'username' => '', 'display_name' => '', 'email' => '', 'role_id' => 0, 'active' => 1];
    if ($id) { $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]); $u = $st->fetch() ?: redirect('users'); }
    render('user_form', ['u' => $u, 'roles' => db()->query('SELECT * FROM roles ORDER BY is_system DESC, LOWER(name)')->fetchAll()], $id ? 'Benutzer bearbeiten' : 'Neuer Benutzer');
}

function users_save(): void {
    csrf_check();
    $pdo = db(); $id = (int)($_POST['id'] ?? 0);
    $back = fn() => redirect('user_edit', $id ? ['id' => $id] : []);
    $username = post('username'); $role = (int)($_POST['role_id'] ?? 0); $active = isset($_POST['active']) ? 1 : 0; $pw = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $username)) { flash('Der Benutzername darf 3–60 Zeichen lang sein (Buchstaben, Ziffern, . _ @ -).', 'err'); $back(); }
    $email = post('email');
    if ($e = field_too_long('users', ['display_name' => post('display_name'), 'email' => $email])) { flash($e, 'err'); $back(); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Die E-Mail-Adresse ist ungültig.', 'err'); $back(); }
    $r = $pdo->prepare('SELECT * FROM roles WHERE id = ?'); $r->execute([$role]); $roleRow = $r->fetch();
    if (!$roleRow) { flash('Bitte eine Rolle wählen.', 'err'); $back(); }
    $dup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?) AND id <> ?'); $dup->execute([$username, $id]);
    if ((int)$dup->fetchColumn()) { flash('Dieser Benutzername ist schon vergeben.', 'err'); $back(); }
    if (($pw !== '' || !$id) && ($e = password_error($pw, $username))) { flash($e, 'err'); $back(); }
    if (!$id && ($e = saas_limit_error('users'))) { flash($e, 'err'); $back(); }
    if ($id) {
        // der letzte aktive Administrator darf weder deaktiviert noch herabgestuft werden
        $cur = $pdo->prepare('SELECT u.*, r.is_system FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?'); $cur->execute([$id]); $c = $cur->fetch();
        if ($c && (int)$c['is_system'] === 1 && (!$active || (int)$roleRow['is_system'] !== 1) && active_admin_count($id) === 0) { flash('Es muss mindestens ein aktiver Administrator bleiben.', 'err'); $back(); }
        if ($id === (int)current_user()['id'] && !$active) { flash('Sie können sich nicht selbst deaktivieren.', 'err'); $back(); }
        $pdo->prepare('UPDATE users SET username = ?, display_name = ?, email = ?, role_id = ?, active = ? WHERE id = ?')->execute([$username, post('display_name'), $email, $role, $active, $id]);
        if ($pw !== '') $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
    } else {
        $pdo->prepare('INSERT INTO users(username, display_name, email, password_hash, role_id, active) VALUES (?,?,?,?,?,?)')->execute([$username, post('display_name'), $email, password_hash($pw, PASSWORD_DEFAULT), $role, $active]);
    }
    audit($id ? 'user_updated' : 'user_created', 'Benutzer ' . $username . ' (Rolle ' . $roleRow['name'] . ($active ? '' : ', gesperrt') . ')' . ($pw !== '' && $id ? ', Passwort geändert' : ''));
    flash('Benutzer gespeichert.');
    redirect('users');
}

function users_delete(): void {
    csrf_check();
    $pdo = db(); $id = (int)($_POST['id'] ?? 0);
    if ($id === (int)current_user()['id']) { flash('Sie können sich nicht selbst löschen.', 'err'); redirect('users'); }
    if (active_admin_count($id) === 0) { flash('Es muss mindestens ein aktiver Administrator bleiben.', 'err'); redirect('users'); }
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    audit('user_deleted', 'Benutzer-ID ' . $id);
    flash('Benutzer gelöscht.');
    redirect('users');
}

// ---- Rollen ----
function users_roles(): void {
    $rows = db()->query('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count FROM roles r ORDER BY r.is_system DESC, LOWER(r.name)')->fetchAll();
    render('roles', ['roles' => $rows], 'Rollen');
}

function users_role_edit(): void {
    $id = (int)($_GET['id'] ?? 0);
    $r = ['id' => 0, 'name' => '', 'permissions' => '{}', 'is_system' => 0];
    if ($id) { $st = db()->prepare('SELECT * FROM roles WHERE id = ?'); $st->execute([$id]); $r = $st->fetch() ?: redirect('roles'); }
    $perm = (int)$r['is_system'] === 1 ? role_all_write() : (json_decode((string)$r['permissions'], true) ?: []);
    render('role_form', ['r' => $r, 'perm' => $perm], $id ? 'Rolle bearbeiten' : 'Neue Rolle');
}

function users_role_save(): void {
    csrf_check();
    $pdo = db(); $id = (int)($_POST['id'] ?? 0); $name = post('name');
    $back = fn() => redirect('role_edit', $id ? ['id' => $id] : []);
    if ($id) { $st = $pdo->prepare('SELECT is_system FROM roles WHERE id = ?'); $st->execute([$id]); if ((int)$st->fetchColumn() === 1) { flash('Die Administrator-Rolle ist fest und kann nicht geändert werden.', 'err'); redirect('roles'); } }
    if ($name === '' || mb_strlen($name) > 60) { flash('Bitte einen Rollennamen (max. 60 Zeichen) angeben.', 'err'); $back(); }
    $dup = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE LOWER(name) = LOWER(?) AND id <> ?'); $dup->execute([$name, $id]);
    if ((int)$dup->fetchColumn()) { flash('Eine Rolle mit diesem Namen gibt es schon.', 'err'); $back(); }
    $perm = [];
    foreach (array_keys(MODULES) as $m) { $v = (string)($_POST['perm'][$m] ?? ''); if (in_array($v, ['r', 'w'], true)) $perm[$m] = $v; }
    if ($id) $pdo->prepare('UPDATE roles SET name = ?, permissions = ? WHERE id = ?')->execute([$name, json_encode($perm), $id]);
    else $pdo->prepare('INSERT INTO roles(name, permissions, is_system) VALUES (?,?,0)')->execute([$name, json_encode($perm)]);
    audit($id ? 'role_updated' : 'role_created', 'Rolle ' . $name . ': ' . json_encode($perm));
    flash('Rolle gespeichert.');
    redirect('roles');
}

function users_role_delete(): void {
    csrf_check();
    $pdo = db(); $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM roles WHERE id = ?'); $st->execute([$id]); $r = $st->fetch();
    if (!$r) redirect('roles');
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?'); $cnt->execute([$id]);
    if ((int)$r['is_system'] === 1) flash('Die Administrator-Rolle kann nicht gelöscht werden.', 'err');
    elseif ((int)$cnt->fetchColumn() > 0) flash('Die Rolle ist noch Benutzern zugeordnet. Bitte zuerst deren Rolle ändern.', 'err');
    else { $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]); audit('role_deleted', 'Rolle ' . $r['name']); flash('Rolle gelöscht.'); }
    redirect('roles');
}

// ---- Eigenes Konto ----
function users_profile(): void { render('profile', ['u' => current_user()], 'Mein Konto'); }

function users_profile_save(): void {
    csrf_check();
    $u = current_user(); $pdo = db();
    $email = post('email');
    if ($e = field_too_long('users', ['display_name' => post('display_name'), 'email' => $email])) { flash($e, 'err'); redirect('profile'); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Die E-Mail-Adresse ist ungültig.', 'err'); redirect('profile'); }
    $lay = in_array(post('ui_layout'), ['top', 'side'], true) ? post('ui_layout') : '';
    $pdo->prepare('UPDATE users SET display_name = ?, email = ?, ui_layout = ? WHERE id = ?')->execute([post('display_name'), $email, $lay, $u['id']]);
    $new = (string)($_POST['new'] ?? '');
    if ($new !== '' || (string)($_POST['current'] ?? '') !== '') {
        if (!password_verify((string)($_POST['current'] ?? ''), $u['password_hash'])) { flash('Das aktuelle Passwort ist falsch.', 'err'); redirect('profile'); }
        if ($e = password_error($new, (string)$u['username'])) { flash($e, 'err'); redirect('profile'); }
        if ($new !== (string)($_POST['new2'] ?? '')) { flash('Die neuen Passwörter stimmen nicht überein.', 'err'); redirect('profile'); }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        audit('password_changed', 'Eigenes Passwort geändert');
    }
    flash('Konto gespeichert.');
    redirect('profile');
}

function users_twofa(): void { twofa_page('users', current_user(), 'twofa', false); }

function users_audit(): void {
    $q = trim((string)($_GET['q'] ?? '')); $w = ''; $p = [];
    if ($q !== '') { $w = ' WHERE username LIKE ? OR action LIKE ? OR detail LIKE ?'; $p = ["%$q%", "%$q%", "%$q%"]; }
    $st = db()->prepare('SELECT * FROM audit_log' . $w . ' ORDER BY id DESC LIMIT 300'); $st->execute($p);
    render('audit', ['rows' => $st->fetchAll(), 'q' => $q, 'route' => 'audit', 'central' => false], 'Protokoll');
}
