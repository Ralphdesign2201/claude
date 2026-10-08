<?php
declare(strict_types=1);

const SETTING_FIELDS = ['company', 'owner', 'street', 'zip', 'city', 'phone', 'email', 'website', 'tax_number', 'vat_id', 'bank', 'iban', 'bic', 'invoice_prefix', 'offer_prefix', 'delivery_prefix', 'payment_days', 'default_intro', 'footer_text', 'reminder_fee_2', 'reminder_fee_3', 'interest_rate', 'mail_mode', 'mail_from', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'mail_signature'];

function settings_index(): void {
    $s = []; foreach (SETTING_FIELDS as $f) $s[$f] = setting($f);
    $s['offer_prefix'] = setting('offer_prefix', 'AN-'); $s['delivery_prefix'] = setting('delivery_prefix', 'LS-');
    $s['small_business'] = setting('small_business');
    $s['zugferd'] = setting('zugferd', '1');
    $s['mail_mode'] = setting('mail_mode', 'mail'); $s['smtp_secure'] = setting('smtp_secure', 'tls'); $s['smtp_port'] = setting('smtp_port', '587');
    $s['mail_copy'] = setting('mail_copy', '1'); $s['has_smtp_pass'] = setting('smtp_pass') !== '';
    render('settings', ['s' => $s, 'hasLogo' => is_file(data_dir() . '/logo.jpg'), 'gd' => extension_loaded('gd')], 'Einstellungen');
}

function settings_save(): void {
    csrf_check();
    foreach (SETTING_FIELDS as $f) if (mb_strlen(post($f)) > 4000) { flash('Das Feld „' . $f . '“ ist zu lang (höchstens 4000 Zeichen).', 'err'); redirect('settings'); }
    foreach (SETTING_FIELDS as $f) {
        $v = post($f);
        if ($f === 'iban') $v = strtoupper(preg_replace('/\s+/', ' ', $v));
        if (in_array($f, ['reminder_fee_2', 'reminder_fee_3', 'interest_rate'], true)) $v = $v === '' ? '' : number_format(max(0, parse_decimal($v)), 2, ',', '');
        if ($f === 'payment_days') $v = (string)max(0, min(365, (int)$v));
        if ($f === 'invoice_prefix' || $f === 'offer_prefix' || $f === 'delivery_prefix') $v = preg_replace('/[^A-Za-z0-9\-_\/]/', '', $v);
        if ($f === 'mail_mode' && !in_array($v, ['mail', 'smtp'], true)) $v = 'mail';
        if ($f === 'smtp_secure' && !in_array($v, ['tls', 'ssl', 'none'], true)) $v = 'tls';
        set_setting($f, $v);
    }
    set_setting('small_business', isset($_POST['small_business']) ? '1' : '0');
    if (post('smtp_pass') !== '') set_setting('smtp_pass', post('smtp_pass'));
    if (isset($_POST['clear_smtp_pass'])) set_setting('smtp_pass', '');
    set_setting('mail_copy', isset($_POST['mail_copy']) ? '1' : '0');
    set_setting('zugferd', isset($_POST['zugferd']) ? '1' : '0');

    if (isset($_POST['remove_logo'])) @unlink(data_dir() . '/logo.jpg');
    if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $err = save_logo($_FILES['logo']['tmp_name']);
        if ($err) { flash($err, 'err'); redirect('settings'); }
    }
    audit('settings_saved', 'Einstellungen geändert');
    flash('Einstellungen gespeichert.');
    redirect('settings');
}

function save_logo(string $tmp): ?string {
    $info = @getimagesize($tmp);
    if (!$info) return 'Die Logo-Datei ist kein gültiges Bild.';
    if (filesize($tmp) > 5 * 1024 * 1024) return 'Das Logo ist größer als 5 MB.';
    $dest = data_dir() . '/logo.jpg';
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

function settings_logo(): void {
    $f = data_dir() . '/logo.jpg';
    if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=300');
    readfile($f);
}
