<?php
declare(strict_types=1);

const SETTING_FIELDS = ['company', 'owner', 'street', 'zip', 'city', 'phone', 'email', 'website', 'tax_number', 'vat_id', 'bank', 'iban', 'bic', 'invoice_prefix', 'payment_days', 'default_intro', 'footer_text'];

function settings_index(): void {
    $s = []; foreach (SETTING_FIELDS as $f) $s[$f] = setting($f);
    $s['small_business'] = setting('small_business');
    $s['zugferd'] = setting('zugferd', '1');
    render('settings', ['s' => $s, 'hasLogo' => is_file(APP_STORAGE . '/logo.jpg'), 'gd' => extension_loaded('gd')], 'Einstellungen');
}

function settings_save(): void {
    csrf_check();
    foreach (SETTING_FIELDS as $f) {
        $v = post($f);
        if ($f === 'iban') $v = strtoupper(preg_replace('/\s+/', ' ', $v));
        if ($f === 'payment_days') $v = (string)max(0, min(365, (int)$v));
        if ($f === 'invoice_prefix') $v = preg_replace('/[^A-Za-z0-9\-_\/]/', '', $v);
        set_setting($f, $v);
    }
    set_setting('small_business', isset($_POST['small_business']) ? '1' : '0');
    set_setting('zugferd', isset($_POST['zugferd']) ? '1' : '0');

    if (isset($_POST['remove_logo'])) @unlink(APP_STORAGE . '/logo.jpg');
    if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $err = save_logo($_FILES['logo']['tmp_name']);
        if ($err) { flash($err, 'err'); redirect('settings'); }
    }
    flash('Einstellungen gespeichert.');
    redirect('settings');
}

function save_logo(string $tmp): ?string {
    $info = @getimagesize($tmp);
    if (!$info) return 'Die Logo-Datei ist kein gültiges Bild.';
    if (filesize($tmp) > 5 * 1024 * 1024) return 'Das Logo ist größer als 5 MB.';
    $dest = APP_STORAGE . '/logo.jpg';
    if (extension_loaded('gd')) {
        $data = file_get_contents($tmp);
        $im = @imagecreatefromstring($data);
        if (!$im) return 'Das Bildformat wird nicht unterstützt (JPG, PNG, GIF, WebP).';
        $w = imagesx($im); $h = imagesy($im);
        $scale = min(1, 900 / max(1, $w));
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($out, $dest, 90);
        return null;
    }
    if ($info[2] !== IMAGETYPE_JPEG) return 'Auf diesem Server ist nur JPG als Logo möglich (PHP-GD fehlt).';
    return move_uploaded_file($tmp, $dest) ? null : 'Logo konnte nicht gespeichert werden.';
}

function settings_password(): void {
    csrf_check();
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify((string)($_POST['current'] ?? ''), setting('password_hash'))) flash('Das aktuelle Passwort ist falsch.', 'err');
    elseif (strlen($new) < 8) flash('Das neue Passwort muss mindestens 8 Zeichen lang sein.', 'err');
    elseif ($new !== (string)($_POST['new2'] ?? '')) flash('Die neuen Passwörter stimmen nicht überein.', 'err');
    else { set_setting('password_hash', password_hash($new, PASSWORD_DEFAULT)); flash('Passwort geändert.'); }
    redirect('settings');
}

/** Download einer konsistenten Kopie der Datenbank. */
function settings_backup(): void {
    csrf_check();
    $tmp = APP_STORAGE . '/backup_' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
    } catch (Throwable $e) { // ältere SQLite-Version
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        copy(APP_STORAGE . '/rechnung.sqlite', $tmp);
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="rechnungen_' . date('Y-m-d') . '.sqlite"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
}

function settings_logo(): void {
    $f = APP_STORAGE . '/logo.jpg';
    if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=300');
    readfile($f);
}
