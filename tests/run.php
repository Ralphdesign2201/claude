<?php

declare(strict_types=1);

/**
 * End-to-End-Test: startet den PHP-Entwicklungsserver mit einer frischen Temp-Datenbank
 * und prüft die API per HTTP.   Aufruf: php tests/run.php
 */

$root = dirname(__DIR__);
$port = (int) (getenv('TEST_PORT') ?: 18080);
$base = "http://127.0.0.1:$port";
$tmp = sys_get_temp_dir() . '/crm-test-' . bin2hex(random_bytes(4));
mkdir($tmp);

$mailDir = "$tmp/mail";
mkdir($mailDir);
$smtpPort = $port + 1;
$env = [
    'SMTP_HOST' => '127.0.0.1',
    'SMTP_PORT' => (string) $smtpPort,
    'SMTP_ENCRYPTION' => 'none',
    'SMTP_USER' => 'mailer',
    'SMTP_PASSWORD' => 'secret',
    'MAIL_FROM' => 'studio@example.com',
    'COMPANY_NAME' => 'Ralph Design',
    'COMPANY_ADDRESS' => 'Studiostraße 5|20095 Hamburg',
    'COMPANY_EMAIL' => 'hallo@ralph-design.de',
    'COMPANY_IBAN' => 'DE89 3704 0044 0532 0130 00',
    'CRON_TOKEN' => 'cron-token-cron-token-123',
    'REMINDER_FEE_2' => '5',
    'BACKUP_DIR' => "$tmp/backups",
    'UPLOAD_DIR' => "$tmp/uploads",
    'APP_URL' => 'https://crm.example.com',
    'PRIVACY_URL' => 'https://crm.example.com/datenschutz',
    'REGISTER_RATE_LIMIT_MAX' => '7',
    'DATABASE_PATH' => "$tmp/test.db",
    'JWT_SECRET' => 'test-secret-test-secret-123456',
    'ALLOW_REGISTRATION' => 'false',
    'LOGIN_RATE_LIMIT_MAX' => '5',
    'RATE_LIMIT_MAX' => '20000',
    'PATH' => (string) getenv('PATH'),
];

$run = static function (string $cmd) use ($env, $root): void {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($p);
};
$run(PHP_BINARY . ' bin/migrate.php');

$sink = proc_open([PHP_BINARY, 'tests/smtp_sink.php', (string) $smtpPort, $mailDir], [1 => ['file', "$tmp/sink.log", 'a'], 2 => ['file', "$tmp/sink.log", 'a']], $sinkPipes, $root, $env);

$server = proc_open(
    [PHP_BINARY, '-d', 'upload_max_filesize=25M', '-d', 'post_max_size=26M', '-S', "127.0.0.1:$port", '-t', 'public', 'public/index.php'],
    [1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']],
    $pipes,
    $root,
    $env,
);

register_shutdown_function(static function () use ($server, $sink, $tmp) {
    proc_terminate($server);
    proc_terminate($sink);
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    $remove("$path/$f");
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $remove($tmp);
});

for ($i = 0; $i < 50; $i++) {
    if (@file_get_contents("$base/health") !== false) {
        break;
    }
    usleep(100000);
}

$passed = 0;
$failed = 0;
$token = null;

/** @return array{0:int,1:mixed,2:string} */
function call(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = []): array
{
    global $base;
    $h = $headers;
    if ($token) {
        $h[] = "Authorization: Bearer $token";
    }
    $content = null;
    if ($body !== null) {
        $content = is_string($body) ? $body : json_encode($body);
        if (!is_string($body)) {
            $h[] = 'Content-Type: application/json';
        }
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $h),
        'content' => $content,
        'ignore_errors' => true,
    ]]);
    $raw = (string) @file_get_contents($base . $path, false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), json_decode($raw, true), $raw];
}

/** @return list<array{head:string,body:string,raw:string}> alle beim Test-Mailserver eingegangenen Mails, älteste zuerst */
function mails(): array
{
    global $mailDir;
    $files = glob("$mailDir/*.eml") ?: [];
    sort($files);
    return array_map(static function ($f) {
        $raw = (string) file_get_contents($f);
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        return ['head' => $head, 'body' => $body, 'raw' => $raw];
    }, $files);
}

/** @return array<string,string> Anhänge einer Mail: Dateiname => Inhalt */
function attachments(array $mail): array
{
    preg_match_all('/Content-Disposition: attachment; filename="([^"]+)"\r\n\r\n(.*?)\r\n--/s', $mail['raw'], $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $part) {
        $out[$part[1]] = (string) base64_decode(preg_replace('/\s+/', '', $part[2]));
    }
    return $out;
}

function mailText(array $mail): string
{
    preg_match('/Content-Transfer-Encoding: base64\r\n\r\n(.*?)(\r\n--|\z)/s', $mail['raw'], $m);
    return (string) base64_decode(preg_replace('/\s+/', '', $m[1] ?? ''));
}

/** Text aus den (Flate-komprimierten) Inhaltsströmen eines PDFs, grob genug für Stichproben. */
function pdfText(string $pdf): string
{
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
    $out = '';
    foreach ($m[1] as $stream) {
        $out .= (string) @gzuncompress($stream);
    }
    return $out;
}

function check(string $name, bool $ok, mixed $detail = null): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== null ? ' → ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
    }
}

function expect(string $name, array $res, int $status): void
{
    check($name, $res[0] === $status, "Status {$res[0]} statt $status: " . substr($res[2], 0, 300));
}

echo "Health & Auth\n";
$res = call('GET', '/health');
expect('GET /health', $res, 200);
$res = call('GET', '/');
check('Startseite (Frontend) wird ausgeliefert', $res[0] === 200 && str_contains($res[2], '<title>Webdesigner CRM</title>'), substr($res[2], 0, 100));
$headers = get_headers($base . '/', true);
check('Startseite mit Content-Security-Policy', str_contains((string) ($headers['Content-Security-Policy'] ?? ''), "script-src 'self'"), $headers);
$res = call('GET', '/assets/app.js');
check('Frontend-Skript abrufbar', $res[0] === 200 && str_contains($res[2], 'use strict'));
$res = call('GET', '/assets/app.css');
check('Frontend-Styles abrufbar', $res[0] === 200 && str_contains($res[2], '--accent'));
expect('API ohne Token → 401', call('GET', '/api/clients'), 401);
expect('Ungültiges Token → 401', call('GET', '/api/clients', null, 'abc.def.ghi'), 401);
expect('Unbekannte Route → 404', call('GET', '/api/gibtsnicht', null, null), 404);

$res = call('POST', '/api/auth/register', ['name' => 'Ralph', 'email' => 'Ralph@Example.com', 'password' => 'geheim1234']);
expect('Erster Benutzer registrieren', $res, 201);
check('Erster Benutzer ist ADMIN, E-Mail kleingeschrieben', ($res[1]['user']['role'] ?? '') === 'ADMIN' && $res[1]['user']['email'] === 'ralph@example.com');
$token = $res[1]['token'] ?? null;
expect('Zweite Registrierung gesperrt → 403', call('POST', '/api/auth/register', ['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'geheim1234']), 403);
expect('Registrierung validiert (400)', call('POST', '/api/auth/register', ['name' => 'X', 'email' => 'kaputt', 'password' => '1']), 400);

$res = call('POST', '/api/auth/login', ['email' => 'ralph@example.com', 'password' => 'geheim1234']);
expect('Login', $res, 200);
$token = $res[1]['token'];
$res = call('GET', '/api/auth/me', null, $token);
check('GET /api/auth/me', $res[0] === 200 && $res[1]['email'] === 'ralph@example.com' && !isset($res[1]['passwordHash']), $res[2]);

echo "Kunden\n";
$res = call('POST', '/api/clients', ['name' => 'Anna Beispiel', 'company' => 'Beispiel GmbH', 'email' => 'anna@beispiel.de', 'status' => 'ACTIVE', 'tags' => 'webdesign,stammkunde'], $token);
expect('Kunde anlegen', $res, 201);
$clientId = $res[1]['id'];
check('Owner automatisch gesetzt', !empty($res[1]['ownerId']));
$res = call('POST', '/api/clients', ['name' => '', 'status' => 'KAPUTT', 'email' => 'x'], $token);
check('Kunde mit ungültigen Daten → 400 mit Details', $res[0] === 400 && isset($res[1]['details']['fieldErrors']['name'], $res[1]['details']['fieldErrors']['status']), $res[2]);
call('POST', '/api/clients', ['name' => 'Bernd Müller', 'company' => 'Müller 100% & Söhne'], $token);
$res = call('GET', '/api/clients?search=' . urlencode('100%'), null, $token);
check('Suche maskiert LIKE-Sonderzeichen', $res[0] === 200 && count($res[1]['items']) === 1 && $res[1]['meta']['total'] === 1, $res[2]);
$res = call('GET', '/api/clients?status=ACTIVE&sort=name', null, $token);
check('Filter nach Status + Liste mit _count/owner', $res[0] === 200 && count($res[1]['items']) === 1 && isset($res[1]['items'][0]['_count']['projects']) && $res[1]['items'][0]['owner']['name'] === 'Ralph', $res[2]);
$res = call('GET', '/api/clients?pageSize=1&page=2', null, $token);
check('Pagination', $res[0] === 200 && $res[1]['meta']['totalPages'] === 2 && count($res[1]['items']) === 1, $res[2]);
$res = call('GET', "/api/clients?search=" . urlencode("' OR 1=1 --"), null, $token);
check('SQL-Injection über search wirkungslos', $res[0] === 200 && count($res[1]['items']) === 0);
$res = call('PATCH', "/api/clients/$clientId", ['phone' => '+49 123'], $token);
check('Kunde ändern', $res[0] === 200 && $res[1]['phone'] === '+49 123' && $res[1]['name'] === 'Anna Beispiel', $res[2]);
expect('Unbekannten Kunden ändern → 404', call('PATCH', '/api/clients/gibtsnicht', ['phone' => '1'], $token), 404);
$res = call('POST', "/api/clients/$clientId/contacts", ['name' => 'Chef', 'isPrimary' => true, 'email' => ''], $token);
check('Ansprechpartner anlegen (isPrimary als bool)', $res[0] === 201 && $res[1]['isPrimary'] === true && $res[1]['email'] === null, $res[2]);
$contactId = $res[1]['id'];

echo "Projekte & Aufgaben\n";
$res = call('POST', '/api/projects', ['clientId' => $clientId, 'name' => 'Relaunch', 'budget' => 6500, 'hourlyRate' => 90, 'dueDate' => '2026-12-31T00:00:00.000Z'], $token);
expect('Projekt anlegen', $res, 201);
$projectId = $res[1]['id'];
expect('Projekt mit unbekanntem Kunden → 400', call('POST', '/api/projects', ['clientId' => 'nope', 'name' => 'X'], $token), 400);
$res = call('POST', "/api/projects/$projectId/tasks", ['title' => 'Wireframes', 'priority' => 'HIGH', 'dueDate' => '2026-11-01'], $token);
expect('Aufgabe anlegen (Datum ohne Uhrzeit)', $res, 201);
$taskId = $res[1]['id'];
check('Datum nach UTC-ISO normalisiert', $res[1]['dueDate'] === '2026-11-01T00:00:00.000Z', $res[2]);
call('POST', "/api/projects/$projectId/tasks", ['title' => 'Ohne Datum'], $token);
$res = call('GET', "/api/projects/$projectId/tasks", null, $token);
check('Aufgaben des Projekts', $res[0] === 200 && count($res[1]) === 2, $res[2]);
$res = call('PATCH', "/api/tasks/$taskId", ['status' => 'DONE'], $token);
check('Aufgabe → DONE', $res[0] === 200 && $res[1]['status'] === 'DONE', $res[2]);
$res = call('GET', '/api/tasks?status=OPEN', null, $token);
check('Aufgabenliste mit Projekt-Info', $res[0] === 200 && count($res[1]) === 1 && isset($res[1][0]['project']['name']), $res[2]);
$res = call('GET', "/api/projects/$projectId", null, $token);
check('Projektdetail', $res[0] === 200 && count($res[1]['tasks']) === 2 && $res[1]['client']['name'] === 'Anna Beispiel', $res[2]);
$res = call('GET', "/api/projects?clientId=$clientId", null, $token);
check('Projektliste mit client/_count', $res[0] === 200 && $res[1]['items'][0]['_count']['tasks'] === 2 && $res[1]['items'][0]['client']['name'] === 'Anna Beispiel', $res[2]);

echo "Zeiterfassung\n";
$res = call('POST', '/api/time-entries', ['projectId' => $projectId, 'taskId' => $taskId, 'minutes' => 90, 'billable' => false, 'description' => 'Konzept'], $token);
check('Zeiteintrag anlegen', $res[0] === 201 && $res[1]['billable'] === false && !empty($res[1]['date']), $res[2]);
expect('Zeiteintrag mit 0 Minuten → 400', call('POST', '/api/time-entries', ['projectId' => $projectId, 'minutes' => 0], $token), 400);
$res = call('GET', "/api/time-entries?projectId=$projectId&from=2020-01-01&to=2099-01-01", null, $token);
check('Zeiteinträge filtern', $res[0] === 200 && count($res[1]) === 1 && $res[1][0]['user']['name'] === 'Ralph' && $res[1][0]['project']['hourlyRate'] == 90, $res[2]);
expect('Ungültiges Datum im Filter → 400', call('GET', '/api/time-entries?from=gestern', null, $token), 400);

echo "Rechnungen\n";
$items = [['description' => 'Konzeption', 'quantity' => 1, 'unitPrice' => 2500], ['description' => 'Entwicklung', 'quantity' => 20, 'unitPrice' => 90]];
$res = call('POST', '/api/invoices', ['clientId' => $clientId, 'projectId' => $projectId, 'items' => $items, 'dueDate' => '2020-01-01T00:00:00.000Z', 'status' => 'SENT'], $token);
expect('Rechnung anlegen', $res, 201);
$invoiceId = $res[1]['id'];
$year = gmdate('Y');
check("Nummer RE-$year-0001", $res[1]['number'] === "RE-$year-0001", $res[1]['number'] ?? null);
check('Summen: 4300 netto, 817 MwSt., 5117 brutto', $res[1]['totals']['subtotal'] == 4300 && $res[1]['totals']['tax'] == 817 && $res[1]['totals']['total'] == 5117, $res[1]['totals'] ?? null);
$res = call('POST', '/api/invoices', ['clientId' => $clientId, 'items' => [['description' => 'Hosting', 'unitPrice' => 10]], 'discount' => 0], $token);
check('Zweite Rechnung: Nummer 0002, quantity-Default 1', ($res[1]['number'] ?? '') === "RE-$year-0002" && $res[1]['items'][0]['quantity'] == 1, $res[2]);
$secondId = $res[1]['id'];
expect('Rechnung löschen', call('DELETE', "/api/invoices/$secondId", null, $token), 204);
$res = call('POST', '/api/invoices', ['clientId' => $clientId, 'items' => [['description' => 'X', 'unitPrice' => 1]]], $token);
check('Nummer nach Löschung nicht doppelt vergeben', ($res[1]['number'] ?? '') === "RE-$year-0002", $res[2]);
expect('Rechnung ohne Positionen → 400', call('POST', '/api/invoices', ['clientId' => $clientId, 'items' => []], $token), 400);

$res = call('GET', '/api/dashboard/summary', null, $token);
check('Dashboard: offen/überfällig vor Zahlung', $res[0] === 200 && $res[1]['revenue']['outstanding'] == 5117 + 1.19 && $res[1]['revenue']['overdue'] == 5117 && $res[1]['revenue']['paid'] == 0, $res[1]['revenue'] ?? $res[2]);

$pdf = call('GET', "/api/invoices/$invoiceId/pdf", null, $token);
check('Rechnungs-PDF: gültiges PDF', $pdf[0] === 200 && str_starts_with($pdf[2], '%PDF-1.4') && str_contains($pdf[2], '%%EOF') && strlen($pdf[2]) > 1500, substr($pdf[2], 0, 80));
$headers = get_headers($base . "/api/invoices/$invoiceId/pdf", true, stream_context_create(['http' => ['header' => "Authorization: Bearer $token"]]));
check('Rechnungs-PDF: Dateiname im Header', str_contains((string) ($headers['Content-Disposition'] ?? ''), "Rechnung-RE-$year-0001.pdf") && str_contains((string) ($headers['Content-Type'] ?? ''), 'application/pdf'), $headers);
expect('Rechnungs-PDF ohne Token → 401', call('GET', "/api/invoices/$invoiceId/pdf"), 401);
expect('Rechnungs-PDF unbekannte Rechnung → 404', call('GET', '/api/invoices/gibtsnicht/pdf', null, $token), 404);
$res = call('POST', "/api/invoices/$invoiceId/payments", ['amount' => 5000, 'method' => 'Überweisung'], $token);
expect('Teilzahlung', $res, 201);
$res = call('GET', "/api/invoices/$invoiceId", null, $token);
check('Teilzahlung: Status bleibt SENT, Rest 117', $res[1]['status'] === 'SENT' && $res[1]['totals']['balance'] == 117, $res[1]['totals'] ?? null);
call('POST', "/api/invoices/$invoiceId/payments", ['amount' => 117], $token);
$res = call('GET', "/api/invoices/$invoiceId", null, $token);
check('Volle Zahlung setzt Status PAID + paidAt', $res[1]['status'] === 'PAID' && !empty($res[1]['paidAt']) && $res[1]['totals']['balance'] == 0, $res[2]);
$res = call('PATCH', "/api/invoices/$invoiceId", ['items' => [['description' => 'Neu', 'quantity' => 2, 'unitPrice' => 50]], 'status' => 'SENT'], $token);
check('Rechnung ändern ersetzt Positionen, paidAt zurückgesetzt', $res[0] === 200 && count($res[1]['items']) === 1 && $res[1]['totals']['subtotal'] == 100 && $res[1]['paidAt'] === null, $res[2]);
$res = call('GET', '/api/invoices?search=' . "RE-$year", null, $token);
check('Rechnungsliste mit totals & client', $res[0] === 200 && isset($res[1]['items'][0]['totals']['balance']) && isset($res[1]['items'][0]['client']['name']), $res[2]);
$res = call('GET', "/api/clients/$clientId", null, $token);
check('Kundendetail mit allen Relationen', $res[0] === 200 && count($res[1]['projects']) === 1 && count($res[1]['invoices']) === 2 && count($res[1]['contacts']) === 1 && count($res[1]['activities']) >= 3, array_keys($res[1] ?? []));

echo "Verträge & Notizen\n";
$res = call('POST', '/api/contracts', ['clientId' => $clientId, 'title' => 'Wartungsvertrag', 'value' => 1200.5, 'startDate' => '2026-01-01T00:00:00Z', 'endDate' => ''], $token);
check('Vertrag anlegen', $res[0] === 201 && $res[1]['endDate'] === null && $res[1]['startDate'] === '2026-01-01T00:00:00.000Z', $res[2]);
$contractId = $res[1]['id'];
expect('Vertrag lesen', call('GET', "/api/contracts/$contractId", null, $token), 200);
$res = call('PATCH', "/api/contracts/$contractId", ['status' => 'SIGNED', 'signedAt' => '2026-02-01T10:00:00+01:00'], $token);
check('Vertrag unterschreiben (Zeitzone → UTC)', $res[0] === 200 && $res[1]['signedAt'] === '2026-02-01T09:00:00.000Z', $res[2]);
$res = call('POST', '/api/notes', ['clientId' => $clientId, 'body' => 'Wichtig', 'pinned' => true], $token);
check('Notiz anlegen', $res[0] === 201 && $res[1]['pinned'] === true, $res[2]);
call('POST', '/api/notes', ['clientId' => $clientId, 'body' => 'Weniger wichtig'], $token);
$res = call('GET', "/api/notes?clientId=$clientId", null, $token);
check('Notizen: angeheftete zuerst, Autor eingebettet', $res[0] === 200 && $res[1][0]['pinned'] === true && $res[1][0]['author']['name'] === 'Ralph', $res[2]);

echo "Dokumente\n";
$boundary = 'bnd' . bin2hex(random_bytes(6));
$multipart = static function (string $filename, string $content) use ($boundary, $clientId): string {
    return "--$boundary\r\nContent-Disposition: form-data; name=\"clientId\"\r\n\r\n$clientId\r\n"
        . "--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\nContent-Type: text/plain\r\n\r\n$content\r\n--$boundary--\r\n";
};
$hdr = ["Content-Type: multipart/form-data; boundary=$boundary"];
$res = call('POST', '/api/documents', $multipart('briefing.txt', 'Hallo Welt'), $token, $hdr);
check('Dokument hochladen', $res[0] === 201 && $res[1]['name'] === 'briefing.txt' && $res[1]['size'] === 10 && str_ends_with($res[1]['url'], '.txt'), $res[2]);
$docId = $res[1]['id'];
$docUrl = $res[1]['url'];
$res = call('GET', $docUrl);
check('Upload abrufbar mit nosniff', $res[0] === 200 && $res[2] === 'Hallo Welt', $res[2]);
$res = call('POST', '/api/documents', $multipart('../../evil.php', '<?php echo 1;'), $token, $hdr);
check('Upload evil.php wird als .bin gespeichert, Pfad entfernt', $res[0] === 201 && str_ends_with($res[1]['url'], '.bin') && $res[1]['name'] === 'evil.php' && !str_contains($res[1]['url'], '..'), $res[2]);
$evilId = $res[1]['id'];
$evilUrl = $res[1]['url'];
$headers = get_headers($base . $evilUrl, true);
check('.bin wird als Download ausgeliefert', str_contains((string) ($headers['Content-Disposition'] ?? ''), 'attachment') && ($headers['X-Content-Type-Options'] ?? '') === 'nosniff', $headers);
expect('Pfad-Traversal blockiert', call('GET', '/uploads/..%2F..%2F.env'), 404);
expect('Upload ohne Datei → 400', call('POST', '/api/documents', "--$boundary--\r\n", $token, $hdr), 400);
expect('Dokument löschen', call('DELETE', "/api/documents/$docId", null, $token), 204);
expect('Datei nach Löschung weg', call('GET', $docUrl), 404);
call('DELETE', "/api/documents/$evilId", null, $token);

echo "E-Mail-Versand (echter SMTP-Server)\n";
$res = call('GET', '/api/settings', null, $token);
check('Einstellungen: Mail eingerichtet', $res[0] === 200 && $res[1]['mailConfigured'] === true && $res[1]['mailDriver'] === 'smtp', $res[2]);
$res = call('POST', '/api/invoices', ['clientId' => $clientId, 'dueDate' => '2030-01-01T00:00:00Z', 'items' => [['description' => 'Mail-Test', 'quantity' => 2, 'unitPrice' => 100]]], $token);
$mailInvoiceId = $res[1]['id'];
$mailInvoiceNumber = $res[1]['number'];
$res = call('GET', "/api/invoices/$mailInvoiceId/email-draft", null, $token);
check('E-Mail-Entwurf: Empfänger, Betreff, Text, Anhang', $res[0] === 200 && $res[1]['to'] === 'anna@beispiel.de' && str_contains($res[1]['subject'], $mailInvoiceNumber) && str_contains($res[1]['message'], 'Guten Tag Chef') && $res[1]['attachment'] === "Rechnung-$mailInvoiceNumber.pdf" && $res[1]['mailConfigured'] === true, $res[2]);
$draft = $res[1];
expect('Versand mit ungültiger Adresse → 400', call('POST', "/api/invoices/$mailInvoiceId/send", ['to' => "x@y.de\r\nBcc: evil@x.de", 'subject' => 'a', 'message' => 'b'], $token), 400);
$before = count(mails());
$res = call('POST', "/api/invoices/$mailInvoiceId/send", ['to' => $draft['to'], 'subject' => 'Rechnung für Müller & Söhne', 'message' => $draft['message']], $token);
check('Rechnung senden: Status Entwurf → Versendet, Versand protokolliert', $res[0] === 200 && $res[1]['status'] === 'SENT' && count($res[1]['emails']) === 1 && $res[1]['emails'][0]['status'] === 'SENT', $res[2]);
$all = mails();
$mail = end($all);
check('SMTP: genau eine Mail empfangen, mit Anmeldung', count($all) === $before + 1 && str_contains($mail['head'], 'X-Authenticated: yes') && str_contains($mail['head'], 'X-Envelope-To: anna@beispiel.de') && str_contains($mail['head'], 'X-Envelope-From: studio@example.com'), $mail['head']);
check('Mail-Header: Umlaute im Betreff kodiert, Reply-To gesetzt', str_contains($mail['head'], '=?UTF-8?B?') && !preg_match('/Subject:[^\r\n]*[äöüÄÖÜß]/u', $mail['head']) && str_contains($mail['head'], 'Reply-To: hallo@ralph-design.de') && !str_contains($mail['head'], 'evil'), $mail['head']);
$att = attachments($mail);
check('Mail-Anhang: Rechnungs-PDF', array_keys($att) === ["Rechnung-$mailInvoiceNumber.pdf"] && str_starts_with(current($att), '%PDF-1.4') && str_contains(pdfText(current($att)), $mailInvoiceNumber), array_keys($att));
check('Mail-Text im Klartext lesbar', str_contains(mailText($mail), 'Guten Tag Chef') && str_contains(mailText($mail), 'Freundliche Grüße'), mailText($mail));
$before = count(mails());
$res = call('POST', "/api/invoices/$mailInvoiceId/send", ['to' => 'reject@example.com', 'subject' => 'x', 'message' => 'y'], $token);
check('Abgelehnter Empfänger → 502 mit Servermeldung', $res[0] === 502 && str_contains((string) ($res[1]['error'] ?? ''), '550'), $res[2]);
$res = call('GET', "/api/invoices/$mailInvoiceId", null, $token);
check('Fehlversuch im Verlauf als FAILED protokolliert', count($res[1]['emails']) === 2 && $res[1]['emails'][0]['status'] === 'FAILED' && $res[1]['emails'][1]['status'] === 'SENT', $res[1]['emails'] ?? null);
check('Bei Fehler wurde nichts zugestellt', count(mails()) === $before);

echo "Angebote\n";
$res = call('POST', '/api/quotes', ['clientId' => $clientId, 'projectId' => $projectId, 'items' => [['description' => 'Webdesign Startseite', 'quantity' => 1, 'unitPrice' => 1800], ['description' => 'Kontaktformular', 'quantity' => 3, 'unitPrice' => 120]], 'discount' => 60], $token);
expect('Angebot anlegen', $res, 201);
$quoteId = $res[1]['id'];
check("Nummer AN-$year-0001, gültig 30 Tage, Summen", $res[1]['number'] === "AN-$year-0001" && $res[1]['status'] === 'DRAFT' && $res[1]['validUntil'] > gmdate('Y-m-d\TH:i:s', strtotime('+29 days')) && $res[1]['totals']['subtotal'] == 2160 && $res[1]['totals']['tax'] == 399 && $res[1]['totals']['total'] == 2499, $res[1]['totals'] ?? $res[2]);
$res = call('POST', '/api/quotes', ['clientId' => $clientId, 'items' => [['description' => 'X', 'unitPrice' => 1]]], $token);
check('Zweite Nummer AN-0002', ($res[1]['number'] ?? '') === "AN-$year-0002", $res[2]);
$secondQuoteId = $res[1]['id'];
expect('Angebot ohne Positionen → 400', call('POST', '/api/quotes', ['clientId' => $clientId, 'items' => []], $token), 400);
$res = call('GET', "/api/quotes/$quoteId/pdf", null, $token);
check('Angebots-PDF mit „Angebot“ und Gültigkeit', $res[0] === 200 && str_starts_with($res[2], '%PDF') && str_contains(pdfText($res[2]), 'Angebot AN-') && str_contains(pdfText($res[2]), "G\xFCltig bis") && str_contains(pdfText($res[2]), 'Wir freuen uns'), substr(pdfText($res[2]), 0, 200));
$res = call('GET', "/api/quotes?status=DRAFT", null, $token);
check('Angebotsliste mit Summen und Kunde', $res[0] === 200 && count($res[1]['items']) === 2 && isset($res[1]['items'][0]['totals']['total'], $res[1]['items'][0]['client']['name']), $res[2]);
$res = call('GET', "/api/quotes/$quoteId/email-draft", null, $token);
check('Angebots-E-Mail-Entwurf', $res[0] === 200 && str_contains($res[1]['subject'], 'Angebot AN-') && str_contains($res[1]['message'], 'gültig bis'), $res[2]);
$before = count(mails());
$res = call('POST', "/api/quotes/$quoteId/send", ['to' => 'anna@beispiel.de', 'subject' => $res[1]['subject'], 'message' => $res[1]['message']], $token);
$all = mails();
check('Angebot senden: Status Versendet, PDF-Anhang Angebot-…pdf', $res[0] === 200 && $res[1]['status'] === 'SENT' && count($all) === $before + 1 && array_keys(attachments(end($all))) === ["Angebot-AN-$year-0001.pdf"], $res[2]);
$res = call('POST', "/api/quotes/$quoteId/convert", null, $token);
check('Angebot → Rechnung (Entwurf, gleiche Positionen und Summe)', $res[0] === 201 && $res[1]['status'] === 'DRAFT' && count($res[1]['items']) === 2 && $res[1]['totals']['total'] == 2499 && $res[1]['discount'] == 60 && $res[1]['dueDate'] !== null && $res[1]['projectId'] === $projectId, $res[2]);
$convertedNumber = $res[1]['number'];
$res = call('GET', "/api/quotes/$quoteId", null, $token);
check('Angebot ist angenommen und verweist auf die Rechnung', $res[1]['status'] === 'ACCEPTED' && $res[1]['invoice']['number'] === $convertedNumber, $res[2]);
expect('Zweite Umwandlung → 409', call('POST', "/api/quotes/$quoteId/convert", null, $token), 409);
call('PATCH', "/api/quotes/$secondQuoteId", ['status' => 'DECLINED'], $token);
expect('Abgelehntes Angebot nicht umwandelbar → 400', call('POST', "/api/quotes/$secondQuoteId/convert", null, $token), 400);
$res = call('PATCH', "/api/quotes/$secondQuoteId", ['status' => 'SENT', 'validUntil' => '2020-01-01'], $token);
check('Abgelaufenes Angebot wird als EXPIRED angezeigt', $res[1]['status'] === 'EXPIRED', $res[2]);
expect('Angebot löschen', call('DELETE', "/api/quotes/$secondQuoteId", null, $token), 204);

echo "Mahnwesen\n";
$mk = static function (string $due, string $status, float $price) use ($clientId, $token) {
    $res = call('POST', '/api/invoices', ['clientId' => $clientId, 'status' => $status, 'dueDate' => $due, 'items' => [['description' => 'Hosting-Paket', 'unitPrice' => $price]]], $token);
    return [$res[1]['id'], $res[1]['number']];
};
[$overdueId, $overdueNumber] = $mk('2020-03-01T00:00:00Z', 'SENT', 1000);
[$notDueId] = $mk('2099-03-01T00:00:00Z', 'SENT', 500);
[$draftId] = $mk('2020-03-01T00:00:00Z', 'DRAFT', 500);
$res = call('GET', '/api/reminders/overview', null, $token);
$ids = array_column($res[1]['items'] ?? [], 'id');
check('Überfällig-Übersicht: nur versendete, fällige, offene Rechnungen', $res[0] === 200 && in_array($overdueId, $ids, true) && !in_array($notDueId, $ids, true) && !in_array($draftId, $ids, true) && !in_array($invoiceId, $ids, true), $ids);
$row = current(array_filter($res[1]['items'], static fn ($i) => $i['id'] === $overdueId));
check('Übersicht: Tage überfällig, Stufe 0 → nächste Stufe 1', $row['daysOverdue'] > 1000 && $row['lastLevel'] === 0 && $row['nextLevel'] === 1 && $row['nextLevelName'] === 'Zahlungserinnerung' && $row['totals']['balance'] == 1190 && $row['client']['name'] === 'Anna Beispiel', $row);
expect('Mahnentwurf für Entwurfs-Rechnung → 400', call('GET', "/api/invoices/$draftId/reminder-draft", null, $token), 400);
$res = call('GET', "/api/invoices/$overdueId/reminder-draft", null, $token);
check('Mahnentwurf Stufe 1: Text, Frist, Empfänger', $res[0] === 200 && $res[1]['level'] === 1 && $res[1]['fee'] == 0 && str_contains($res[1]['message'], $overdueNumber) && str_contains($res[1]['message'], '1.190,00 €') && str_contains($res[1]['subject'], 'Zahlungserinnerung') && $res[1]['to'] === 'anna@beispiel.de' && $res[1]['dueDate'] > gmdate('Y-m-d\TH:i:s', strtotime('+6 days')), $res[2]);
$reminderDraft = $res[1];
$res = call('GET', "/api/invoices/$overdueId/reminder-draft?level=2", null, $token);
check('Mahnentwurf Stufe 2: Gebühr aus Einstellung, Text mit Gesamtbetrag', $res[1]['level'] === 2 && $res[1]['fee'] == 5 && str_contains($res[1]['message'], '1.195,00 €') && str_contains($res[1]['message'], 'Mahngebühr 5,00 €'), $res[2]);
expect('Ungültige Mahnstufe → 400', call('GET', "/api/invoices/$overdueId/reminder-draft?level=4", null, $token), 400);
$before = count(mails());
$res = call('POST', "/api/invoices/$overdueId/reminders", ['level' => 1, 'fee' => 0, 'dueDate' => $reminderDraft['dueDate'], 'subject' => $reminderDraft['subject'], 'message' => $reminderDraft['message'], 'send' => true, 'to' => $reminderDraft['to']], $token);
expect('Zahlungserinnerung senden', $res, 201);
check('Erinnerung als versendet gespeichert', !empty($res[1]['emailedAt']) && $res[1]['level'] === 1, $res[2]);
$reminder1 = $res[1]['id'];
$all = mails();
$names = array_keys(attachments(end($all)));
check('Mail enthält Mahn-PDF und die Rechnung', count($all) === $before + 1 && $names === ["Zahlungserinnerung-$overdueNumber.pdf", "Rechnung-$overdueNumber.pdf"], $names);
$res = call('GET', "/api/invoices/$overdueId", null, $token);
check('Rechnung wurde auf ÜBERFÄLLIG gesetzt, Mahnung im Verlauf', $res[1]['status'] === 'OVERDUE' && count($res[1]['reminders']) === 1, $res[2]);
$res = call('GET', '/api/reminders/overview', null, $token);
$row = current(array_filter($res[1]['items'], static fn ($i) => $i['id'] === $overdueId));
check('Übersicht: jetzt Stufe 1 erledigt → nächste Stufe 2', $row['lastLevel'] === 1 && $row['nextLevel'] === 2 && $row['nextLevelName'] === '1. Mahnung', $row);
$res = call('GET', "/api/invoices/$overdueId/reminder-draft", null, $token);
check('Mahnentwurf schlägt automatisch Stufe 2 vor', $res[1]['level'] === 2 && $res[1]['lastLevel'] === 1, $res[2]);
$res = call('POST', "/api/invoices/$overdueId/reminders", ['level' => 2, 'fee' => 5, 'dueDate' => $reminderDraft['dueDate'], 'subject' => '1. Mahnung', 'message' => "Guten Tag,\n\nbitte zahlen Sie.", 'send' => false], $token);
check('Mahnung ohne E-Mail-Versand speichern (z. B. zum Ausdrucken)', $res[0] === 201 && $res[1]['emailedAt'] === null && $res[1]['fee'] == 5, $res[2]);
$reminder2 = $res[1]['id'];
check('Ohne Versand keine zusätzliche Mail', count(mails()) === $before + 1);
$res = call('GET', "/api/reminders/$reminder2/pdf", null, $token);
$text = pdfText($res[2]);
check('Mahn-PDF: Titel, Gebühr, Zahlbetrag 1.195,00 €', $res[0] === 200 && str_starts_with($res[2], '%PDF') && str_contains($text, '1. Mahnung zu Rechnung') && str_contains($text, 'Mahngeb') && str_contains($text, '1.195,00'), substr($text, 0, 300));
expect('Mahn-PDF unbekannt → 404', call('GET', '/api/reminders/gibtsnicht/pdf', null, $token), 404);
expect('Mahnung ohne Empfänger aber mit Versand → 400', call('POST', "/api/invoices/$overdueId/reminders", ['level' => 3, 'dueDate' => $reminderDraft['dueDate'], 'subject' => 'x', 'message' => 'y', 'send' => true], $token), 400);
expect('Mahnung für Entwurfs-Rechnung → 400', call('POST', "/api/invoices/$draftId/reminders", ['level' => 1, 'dueDate' => $reminderDraft['dueDate'], 'subject' => 'x', 'message' => 'y'], $token), 400);
expect('Mahnstufe 4 → 400', call('POST', "/api/invoices/$overdueId/reminders", ['level' => 4, 'dueDate' => $reminderDraft['dueDate'], 'subject' => 'x', 'message' => 'y'], $token), 400);
$before = count(mails());
$res = call('POST', "/api/invoices/$overdueId/reminders", ['level' => 3, 'dueDate' => $reminderDraft['dueDate'], 'subject' => 'x', 'message' => 'y', 'send' => true, 'to' => 'reject@example.com'], $token);
$res2 = call('GET', "/api/invoices/$overdueId", null, $token);
check('Fehlgeschlagener Mahnversand wird nicht als Mahnung gespeichert', $res[0] === 502 && count($res2[1]['reminders']) === 2 && count(mails()) === $before, $res[2]);
expect('Mahnung löschen', call('DELETE', "/api/reminders/$reminder1", null, $token), 204);
call('POST', "/api/invoices/$overdueId/payments", ['amount' => 1190], $token);
$res = call('GET', '/api/reminders/overview', null, $token);
check('Bezahlte Rechnung verschwindet aus der Mahnübersicht', !in_array($overdueId, array_column($res[1]['items'], 'id'), true), $res[2]);
expect('Bezahlte Rechnung nicht mehr mahnbar → 400', call('GET', "/api/invoices/$overdueId/reminder-draft", null, $token), 400);

echo "Wiederkehrende Rechnungen (Abos)\n";
require_once $root . '/src/bootstrap.php';
$ts = static fn (string $start, string $unit, int $n) => App\Services\RecurringService::iso(App\Services\RecurringService::scheduleDate($start, $unit, $n));
check('Zeitplan: 31.01. monatlich → 29.02. (Schaltjahr), dann wieder 31.03.', $ts('2024-01-31T00:00:00Z', 'MONTHLY', 1) === '2024-02-29T00:00:00.000Z' && $ts('2024-01-31T00:00:00Z', 'MONTHLY', 2) === '2024-03-31T00:00:00.000Z' && $ts('2024-01-31T00:00:00Z', 'MONTHLY', 4) === '2024-05-31T00:00:00.000Z');
check('Zeitplan: jährlich ab 29.02.2024 → 28.02.2025, 29.02.2028', $ts('2024-02-29T00:00:00Z', 'YEARLY', 1) === '2025-02-28T00:00:00.000Z' && $ts('2024-02-29T00:00:00Z', 'YEARLY', 4) === '2028-02-29T00:00:00.000Z');
check('Zeitplan: quartalsweise und halbjährlich', $ts('2026-11-15T00:00:00Z', 'QUARTERLY', 1) === '2027-02-15T00:00:00.000Z' && $ts('2026-11-15T00:00:00Z', 'HALF_YEARLY', 1) === '2027-05-15T00:00:00.000Z');
check('Platzhalter {monat} {jahr} {zeitraum}', App\Services\RecurringService::fill('Hosting {monat} {jahr} ({zeitraum})', new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31')) === 'Hosting März 2026 (01.03.2026 – 31.03.2026)');

$firstOfMonth2Ago = gmdate('Y-m-d', strtotime('first day of -2 months'));
$today = gmdate('Y-m-d');
$res = call('POST', '/api/recurring', ['clientId' => $clientId, 'title' => 'Hosting Paket M', 'intervalUnit' => 'MONTHLY', 'startDate' => $firstOfMonth2Ago, 'items' => [['description' => 'Hosting Paket M – {monat} {jahr} ({zeitraum})', 'quantity' => 1, 'unitPrice' => 19.9]]], $token);
expect('Monatliches Hosting-Abo anlegen', $res, 201);
$hostingId = $res[1]['id'];
check('Abo: 19,90 € netto/Monat, aktiv, nächster Lauf = Start', $res[1]['active'] === true && $res[1]['monthlyNet'] == 19.9 && $res[1]['nextRunDate'] === $firstOfMonth2Ago . 'T00:00:00.000Z' && $res[1]['occurrence'] === 0, $res[2]);
$res = call('POST', '/api/recurring', ['clientId' => $clientId, 'title' => 'Domain beispiel.de', 'intervalUnit' => 'YEARLY', 'startDate' => '2099-06-01', 'taxRate' => 19, 'items' => [['description' => 'Domain beispiel.de ({zeitraum})', 'unitPrice' => 12]]], $token);
$domainId = $res[1]['id'];
$res = call('GET', '/api/recurring', null, $token);
check('Abo-Übersicht: Monatsumsatz 19,90 + 1,00 = 20,90 €, Jahresumsatz 250,80 €', $res[0] === 200 && count($res[1]['items']) === 2 && $res[1]['summary']['active'] === 2 && $res[1]['summary']['monthlyRevenue'] == 20.9 && $res[1]['summary']['yearlyRevenue'] == 250.8, $res[1]['summary'] ?? $res[2]);
expect('Enddatum vor Startdatum → 400', call('POST', '/api/recurring', ['clientId' => $clientId, 'title' => 'X', 'startDate' => '2026-05-01', 'endDate' => '2026-01-01', 'items' => [['description' => 'X', 'unitPrice' => 1]]], $token), 400);
expect('Abo ohne Positionen → 400', call('POST', '/api/recurring', ['clientId' => $clientId, 'title' => 'X', 'startDate' => '2026-05-01', 'items' => []], $token), 400);
expect('Cron ohne Token → 401', call('POST', '/api/cron/run'), 401);
expect('Cron mit falschem Token → 401', call('POST', '/api/cron/run', null, null, ['X-Cron-Token: falsch-falsch-falsch']), 401);
expect('Cron-Endpunkt braucht kein Login-Token, Abo-API schon', call('GET', '/api/recurring'), 401);
$res = call('POST', '/api/cron/run', null, null, ['X-Cron-Token: cron-token-cron-token-123']);
check('Cron: erstes Backup entsteht automatisch', is_array($res[1]['backup'] ?? null) && preg_match('/^crm-backup-\d{8}-\d{6}\.zip$/', $res[1]['backup']['name']) === 1 && ($res[1]['backupError'] ?? null) === null, $res[1]['backup'] ?? $res[2]);
check('Cron: 3 versäumte Monate werden als Entwürfe nachgeholt', $res[0] === 200 && $res[1]['created'] === 3 && count(array_unique(array_column($res[1]['runs'], 'number'))) === 3 && !in_array(true, array_column($res[1]['runs'], 'sent'), true), $res[2]);
$res = call('GET', "/api/invoices?recurringId=$hostingId&pageSize=50", null, $token);
$descriptions = array_map(static fn ($i) => $i['items'][0]['description'], $res[1]['items']);
check('Rechnungen aus dem Abo: Entwurf, Platzhalter ersetzt, Betrag 23,68 € brutto', count($res[1]['items']) === 3 && !str_contains(implode('|', $descriptions), '{') && str_contains($descriptions[0] . $descriptions[1] . $descriptions[2], gmdate('Y') ) && $res[1]['items'][0]['status'] === 'DRAFT' && $res[1]['items'][0]['totals']['total'] == 23.68 && $res[1]['items'][0]['recurringId'] === $hostingId, $descriptions);
$res = call('GET', "/api/recurring/$hostingId", null, $token);
check('Abo: Lauf 3 gezählt, nächster Termin = 1. des Folgemonats, Rechnungen verknüpft', $res[1]['occurrence'] === 3 && $res[1]['nextRunDate'] === gmdate('Y-m-d', strtotime('first day of next month')) . 'T00:00:00.000Z' && count($res[1]['invoices']) === 3 && $res[1]['lastRunAt'] !== null, $res[2]);
$res = call('POST', '/api/cron/run', null, null, ['X-Cron-Token: cron-token-cron-token-123']);
check('Cron ist idempotent: zweiter Lauf erzeugt weder Rechnungen noch ein weiteres Backup', $res[0] === 200 && $res[1]['created'] === 0 && $res[1]['backup'] === null, $res[2]);

$res = call('POST', '/api/recurring', ['clientId' => $clientId, 'title' => 'Pflege', 'intervalUnit' => 'QUARTERLY', 'startDate' => $today, 'items' => [['description' => 'Pflege {zeitraum}', 'unitPrice' => 30]]], $token);
$careId = $res[1]['id'];
$res = call('POST', '/api/recurring/run-due', null, $token);
check('„Fällige abrechnen“ per Login (ohne Cron-Token): 1 Rechnung, Entwurf (kein Auto-Versand)', $res[0] === 200 && $res[1]['created'] === 1 && $res[1]['runs'][0]['sent'] === false, $res[2]);
expect('„Fällige abrechnen“ braucht Login → 401', call('POST', '/api/recurring/run-due'), 401);
call('DELETE', "/api/recurring/$careId", null, $token);
$before = count(mails());
$res = call('POST', '/api/recurring', ['clientId' => $clientId, 'title' => 'Homepage-Miete', 'intervalUnit' => 'MONTHLY', 'startDate' => $today, 'autoSend' => true, 'paymentDays' => 7, 'items' => [['description' => 'Homepage-Miete {monat} {jahr}', 'unitPrice' => 49]]], $token);
$rentId = $res[1]['id'];
$res = call('POST', '/api/cron/run', null, null, ['X-Cron-Token: cron-token-cron-token-123']);
check('Cron: genau ein fälliger Zeitraum + „automatisch senden“ → Rechnung per E-Mail raus', $res[0] === 200 && $res[1]['created'] === 1 && $res[1]['runs'][0]['sent'] === true && $res[1]['runs'][0]['error'] === null && count(mails()) === $before + 1, $res[2]);
$res = call('GET', '/api/invoices/' . $res[1]['runs'][0]['invoiceId'], null, $token);
$rentInvoice = $res[1];
$due = new DateTimeImmutable($rentInvoice['dueDate']);
$issue = new DateTimeImmutable($rentInvoice['issueDate']);
check('Auto-Rechnung: Status Versendet, Zahlungsziel 7 Tage, Mailverlauf', $rentInvoice['status'] === 'SENT' && $issue->diff($due)->days === 7 && count($rentInvoice['emails']) === 1 && $rentInvoice['emails'][0]['status'] === 'SENT', $rentInvoice['dueDate']);

$res = call('PATCH', "/api/recurring/$domainId", ['intervalUnit' => 'HALF_YEARLY', 'startDate' => '2099-01-31'], $token);
check('Abo ändern berechnet den nächsten Termin neu', $res[0] === 200 && $res[1]['nextRunDate'] === '2099-01-31T00:00:00.000Z' && $res[1]['intervalUnit'] === 'HALF_YEARLY', $res[2]);
$res = call('POST', "/api/recurring/$domainId/run", null, $token);
check('„Jetzt abrechnen“: Entwurf erzeugt', $res[0] === 201 && $res[1]['status'] === 'DRAFT' && str_contains($res[1]['items'][0]['description'], '31.01.2099 – 30.07.2099'), $res[1]['items'][0]['description'] ?? $res[2]);
$res = call('GET', "/api/recurring/$domainId", null, $token);
check('Danach nächster Termin 31.07.2099 (halbjährlich)', $res[1]['occurrence'] === 1 && $res[1]['nextRunDate'] === '2099-07-31T00:00:00.000Z', $res[2]);

$res = call('POST', '/api/recurring', ['clientId' => $clientId, 'title' => 'Einmal-Abo', 'intervalUnit' => 'YEARLY', 'startDate' => $today, 'endDate' => $today, 'items' => [['description' => 'Jahresgebühr', 'unitPrice' => 10]]], $token);
$endedId = $res[1]['id'];
call('POST', "/api/recurring/$endedId/run", null, $token);
$res = call('GET', "/api/recurring/$endedId", null, $token);
check('Abo mit Enddatum wird nach dem letzten Lauf beendet', $res[1]['active'] === false, $res[2]);
expect('Beendetes Abo kann nicht abgerechnet werden → 400', call('POST', "/api/recurring/$endedId/run", null, $token), 400);
call('PATCH', "/api/recurring/$hostingId", ['active' => false], $token);
$res = call('GET', '/api/recurring?active=true', null, $token);
check('Filter aktiv: pausiertes Abo fehlt', !in_array($hostingId, array_column($res[1]['items'], 'id'), true), array_column($res[1]['items'], 'title'));
expect('Abo löschen (Rechnungen bleiben erhalten)', call('DELETE', "/api/recurring/$hostingId", null, $token), 204);
$res = call('GET', "/api/invoices?search=RE-&pageSize=100", null, $token);
check('Rechnungen des gelöschten Abos bleiben bestehen', count(array_filter($res[1]['items'], static fn ($i) => $i['recurringId'] === null && str_contains($i['items'][0]['description'] ?? '', 'Hosting Paket M'))) === 3, count($res[1]['items']));

echo "Katalog (Kategorien und Produkte)\n";
$mkCat = static function (string $name, array $extra = []) use ($token) {
    return call('POST', '/api/categories', ['name' => $name] + $extra, $token);
};
$res = $mkCat('Webseiten', ['sortOrder' => 1, 'description' => 'Individuelle Websites']);
expect('Kategorie anlegen', $res, 201);
$catWeb = $res[1]['id'];
$catHost = $mkCat('Hosting & Domains', ['sortOrder' => 2])[1]['id'];
$catPrint = $mkCat('Druck', ['sortOrder' => 3])[1]['id'];
$catHidden = $mkCat('Intern', ['sortOrder' => 4, 'active' => false])[1]['id'];
expect('Kategorie ohne Namen → 400', call('POST', '/api/categories', ['name' => ''], $token), 400);
expect('Kategorie ohne Login → 401', call('GET', '/api/categories'), 401);
expect('Unbekannte Kategorie ändern → 404', call('PATCH', '/api/categories/gibtsnicht', ['name' => 'x'], $token), 404);
$res = call('PATCH', "/api/categories/$catHost", ['name' => 'Hosting, Domains & Wartung'], $token);
check('Kategorie umbenennen', $res[0] === 200 && $res[1]['name'] === 'Hosting, Domains & Wartung' && $res[1]['active'] === true, $res[2]);

$mkProduct = static function (array $data) use ($token) {
    return call('POST', '/api/products', $data, $token);
};
$res = $mkProduct(['name' => 'Webseitenerstellung einmalig', 'categoryId' => $catWeb, 'type' => 'ONE_TIME', 'price' => 1500, 'description' => 'Komplette Website nach Ihren Wünschen']);
expect('Einmalprodukt anlegen (Webseitenerstellung)', $res, 201);
$pWeb = $res[1]['id'];
check('Einmalprodukt: 19 % MwSt., kein Rhythmus, aktiv, Kategorie eingebettet', $res[1]['taxRate'] == 19 && $res[1]['intervalUnit'] === null && $res[1]['setupFee'] == 0 && $res[1]['active'] === true && $res[1]['category']['name'] === 'Webseiten' && $res[1]['minQuantity'] == 1, $res[2]);
$pScript = $mkProduct(['name' => 'Skripte einmalig', 'categoryId' => $catWeb, 'type' => 'ONE_TIME', 'price' => 300])[1]['id'];
$res = $mkProduct(['name' => 'Druckauftrag Flyer A5', 'categoryId' => $catPrint, 'type' => 'ONE_TIME', 'price' => 0.12, 'unit' => 'Stück', 'minQuantity' => 100]);
$pFlyer = $res[1]['id'];
check('Druckauftrag mit Einheit und Mindestmenge', $res[1]['unit'] === 'Stück' && $res[1]['minQuantity'] == 100, $res[2]);
$res = $mkProduct(['name' => 'Webhosting M', 'categoryId' => $catHost, 'type' => 'RENTAL', 'price' => 9.9, 'intervalUnit' => 'MONTHLY', 'setupFee' => 19]);
expect('Mietprodukt anlegen (Webhosting)', $res, 201);
$pHost = $res[1]['id'];
check('Mietprodukt: Rhythmus und Einrichtungsgebühr gespeichert', $res[1]['intervalUnit'] === 'MONTHLY' && $res[1]['setupFee'] == 19 && $res[1]['price'] == 9.9, $res[2]);
$pDomain = $mkProduct(['name' => 'Domain beispiel.de', 'categoryId' => $catHost, 'type' => 'RENTAL', 'price' => 14.4, 'intervalUnit' => 'YEARLY'])[1]['id'];
$mkProduct(['name' => 'Miethomepage', 'categoryId' => $catWeb, 'type' => 'RENTAL', 'price' => 49, 'intervalUnit' => 'MONTHLY']);
$mkProduct(['name' => 'Wartungsservice', 'categoryId' => $catHost, 'type' => 'RENTAL', 'price' => 79, 'intervalUnit' => 'MONTHLY']);
$mkProduct(['name' => 'SEO Service', 'categoryId' => $catHost, 'type' => 'RENTAL', 'price' => 199, 'intervalUnit' => 'QUARTERLY']);
$res = $mkProduct(['name' => 'Projektarbeit nach Stunden', 'categoryId' => $catWeb, 'type' => 'HOURLY', 'price' => 85]);
expect('Zeitprodukt anlegen (Stundenbasis)', $res, 201);
$pHours = $res[1]['id'];
check('Zeitprodukt erhält automatisch die Einheit „Std.“', $res[1]['unit'] === 'Std.' && $res[1]['intervalUnit'] === null, $res[2]);
$pInactive = $mkProduct(['name' => 'Auslaufprodukt', 'categoryId' => $catWeb, 'type' => 'ONE_TIME', 'price' => 10, 'active' => false])[1]['id'];
$pHiddenCat = $mkProduct(['name' => 'Nur intern', 'categoryId' => $catHidden, 'type' => 'ONE_TIME', 'price' => 10])[1]['id'];
$pNoCat = $mkProduct(['name' => 'Beratung vor Ort', 'type' => 'ONE_TIME', 'price' => 120])[1]['id'];

$res = $mkProduct(['name' => 'Kaputt', 'type' => 'RENTAL', 'price' => 5]);
check('Mietprodukt ohne Rhythmus → 400 mit Feldfehler', $res[0] === 400 && isset($res[1]['details']['fieldErrors']['intervalUnit']), $res[2]);
$res = $mkProduct(['name' => 'Kaputt', 'type' => 'ONE_TIME', 'price' => -5]);
check('Negativer Preis → 400', $res[0] === 400 && isset($res[1]['details']['fieldErrors']['price']), $res[2]);
expect('Unbekannte Produktart → 400', $mkProduct(['name' => 'Kaputt', 'type' => 'ABO', 'price' => 5]), 400);
expect('Steuersatz über 100 → 400', $mkProduct(['name' => 'Kaputt', 'type' => 'ONE_TIME', 'price' => 5, 'taxRate' => 190]), 400);
expect('Unbekannte Kategorie → 400', $mkProduct(['name' => 'Kaputt', 'type' => 'ONE_TIME', 'price' => 5, 'categoryId' => 'gibtsnicht']), 400);
expect('Mindestmenge 0 → 400', $mkProduct(['name' => 'Kaputt', 'type' => 'ONE_TIME', 'price' => 5, 'minQuantity' => 0]), 400);
$res = $mkProduct(['name' => 'Einmal mit Rhythmus', 'type' => 'ONE_TIME', 'price' => 5, 'intervalUnit' => 'YEARLY', 'setupFee' => 50]);
check('Einmalprodukt ignoriert Rhythmus und Einrichtungsgebühr', $res[0] === 201 && $res[1]['intervalUnit'] === null && $res[1]['setupFee'] == 0, $res[2]);
call('DELETE', '/api/products/' . $res[1]['id'], null, $token);

$res = call('PATCH', "/api/products/$pScript", ['type' => 'RENTAL', 'intervalUnit' => 'YEARLY', 'setupFee' => 10], $token);
check('Produktart ändern: Einmal → Miete', $res[0] === 200 && $res[1]['type'] === 'RENTAL' && $res[1]['intervalUnit'] === 'YEARLY' && $res[1]['setupFee'] == 10, $res[2]);
$res = call('PATCH', "/api/products/$pScript", ['type' => 'ONE_TIME'], $token);
check('Produktart zurück: Rhythmus und Einrichtung werden geleert', $res[1]['type'] === 'ONE_TIME' && $res[1]['intervalUnit'] === null && $res[1]['setupFee'] == 0, $res[2]);
expect('Miete ohne Rhythmus per Änderung → 400', call('PATCH', "/api/products/$pScript", ['type' => 'RENTAL'], $token), 400);
$res = call('PATCH', "/api/products/$pNoCat", ['categoryId' => $catPrint], $token);
check('Produkt in Kategorie verschieben', $res[1]['category']['id'] === $catPrint, $res[2]);
$res = call('PATCH', "/api/products/$pNoCat", ['categoryId' => ''], $token);
check('Produkt aus Kategorie lösen', $res[1]['categoryId'] === null && $res[1]['category'] === null, $res[2]);
$res = call('POST', "/api/products/$pHost/duplicate", null, $token);
check('Produkt duplizieren (inaktive Kopie)', $res[0] === 201 && $res[1]['name'] === 'Webhosting M (Kopie)' && $res[1]['active'] === false && $res[1]['intervalUnit'] === 'MONTHLY' && $res[1]['setupFee'] == 19 && $res[1]['id'] !== $pHost, $res[2]);
call('DELETE', '/api/products/' . $res[1]['id'], null, $token);
expect('Unbekanntes Produkt → 404', call('GET', '/api/products/gibtsnicht', null, $token), 404);

$res = call('GET', '/api/products?type=RENTAL', null, $token);
check('Filter nach Produktart (5 Mietprodukte)', $res[0] === 200 && count($res[1]) === 5 && array_unique(array_column($res[1], 'type')) === ['RENTAL'], count($res[1]));
$res = call('GET', "/api/products?categoryId=$catWeb", null, $token);
check('Filter nach Kategorie', count($res[1]) === 5 && !in_array($pHost, array_column($res[1], 'id'), true), count($res[1]));
$res = call('GET', '/api/products?categoryId=none', null, $token);
check('Filter „Ohne Kategorie“', count($res[1]) === 1 && $res[1][0]['id'] === $pNoCat, $res[2]);
$res = call('GET', '/api/products?active=false', null, $token);
check('Filter inaktive Produkte', count($res[1]) === 1 && $res[1][0]['id'] === $pInactive, $res[2]);
$res = call('GET', '/api/products?search=' . urlencode('hosting'), null, $token);
check('Produktsuche', count($res[1]) === 1 && $res[1][0]['name'] === 'Webhosting M', $res[2]);
$res = call('GET', '/api/categories', null, $token);
check('Kategorien nach Reihenfolge, mit Produktanzahl', count($res[1]) === 4 && array_column($res[1], 'name') === ['Webseiten', 'Hosting, Domains & Wartung', 'Druck', 'Intern'] && $res[1][0]['productCount'] === 5 && $res[1][3]['active'] === false, $res[2]);

// „Endlos“: keine Begrenzung bei Kategorien und Produkten
$bulkCats = [];
for ($i = 1; $i <= 25; $i++) {
    $bulkCats[] = $mkCat("Testkategorie $i", ['sortOrder' => 100 + $i])[1]['id'];
}
$created = 0;
for ($i = 1; $i <= 60; $i++) {
    $created += $mkProduct(['name' => "Testprodukt $i", 'categoryId' => $bulkCats[$i % 25], 'type' => ['ONE_TIME', 'RENTAL', 'HOURLY'][$i % 3], 'price' => $i, 'intervalUnit' => 'MONTHLY'])[0] === 201 ? 1 : 0;
}
check('Keine Obergrenze: 25 weitere Kategorien und 60 weitere Produkte angelegt', $created === 60 && count(call('GET', '/api/categories', null, $token)[1]) === 29 && count(call('GET', '/api/products', null, $token)[1]) === 72);
foreach ($bulkCats as $bc) {
    call('DELETE', "/api/categories/$bc", null, $token);
}
$res = call('GET', '/api/products?search=Testprodukt', null, $token);
check('Kategorie löschen: Produkte bleiben erhalten (ohne Kategorie)', count($res[1]) === 60 && count(array_filter($res[1], static fn ($p) => $p['categoryId'] !== null)) === 0 && count(call('GET', '/api/categories', null, $token)[1]) === 4);
foreach ($res[1] as $bp) {
    call('DELETE', "/api/products/{$bp['id']}", null, $token);
}

$res = call('POST', '/api/catalog/examples', [], $token);
check('Beispielkatalog: 3 Kategorien, 9 Produkte, alle inaktiv', $res[0] === 201 && $res[1]['categories'] === 3 && $res[1]['products'] === 9, $res[2]);
$res = call('GET', '/api/products?active=false&search=' . urlencode('Miethomepage'), null, $token);
check('Beispielprodukt „Miethomepage“: Miete monatlich, 49 €, 199 € Einrichtung, inaktiv', count($res[1]) === 1 && $res[1][0]['type'] === 'RENTAL' && $res[1][0]['intervalUnit'] === 'MONTHLY' && $res[1][0]['price'] == 49 && $res[1][0]['setupFee'] == 199 && $res[1][0]['active'] === false, $res[2]);
$res = call('POST', '/api/catalog/examples', [], $token);
check('Beispielkatalog ist wiederholbar, ohne Doppelte anzulegen', $res[0] === 201 && $res[1]['categories'] === 0 && $res[1]['products'] === 0, $res[2]);
expect('Beispielkatalog ohne Login → 401', call('POST', '/api/catalog/examples', []), 401);
$exampleIds = array_column(call('GET', '/api/categories', null, $token)[1], 'id', 'name');
foreach (['Einmalige Leistungen', 'Mietprodukte', 'Zeitbasierte Leistungen'] as $n) {
    foreach (call('GET', '/api/products?categoryId=' . $exampleIds[$n], null, $token)[1] as $pr) {
        call('DELETE', "/api/products/{$pr['id']}", null, $token);
    }
    call('DELETE', "/api/categories/{$exampleIds[$n]}", null, $token);
}

echo "Bestellungen\n";
$res = call('POST', '/api/clients', ['name' => 'Bea Besteller', 'company' => 'Besteller KG', 'email' => 'bea@besteller.de'], $token);
$orderClient = $res[1]['id'];
$oAccess = call('POST', "/api/clients/$orderClient/portal", [], $token)[1];
$strangerClient = call('POST', '/api/clients', ['name' => 'Fremd Besteller', 'email' => 'fremd-besteller@example.com'], $token)[1]['id'];
$strangerP = ["X-Portal-Token: " . call('POST', "/api/clients/$strangerClient/portal", [], $token)[1]['token']];
$OP = ["X-Portal-Token: {$oAccess['token']}"];
expect('Portal-Katalog ohne Schlüssel → 401', call('GET', '/api/portal/products'), 401);
$res = call('GET', '/api/portal/products', null, null, $OP);
$catalog = $res[1];
$catNames = array_column($catalog, 'name');
check('Portal-Katalog: aktive Kategorien in Reihenfolge, verborgene fehlen, „Weitere Produkte“ am Ende', $res[0] === 200 && $catNames === ['Webseiten', 'Hosting, Domains & Wartung', 'Druck', 'Weitere Produkte'] && !in_array('Intern', $catNames, true), $catNames);
$allProducts = array_merge(...array_column($catalog, 'products'));
$allNames = array_column($allProducts, 'name');
check('Portal-Katalog: inaktive Produkte und Produkte verborgener Kategorien sind unsichtbar', !in_array('Auslaufprodukt', $allNames, true) && !in_array('Nur intern', $allNames, true) && in_array('Webhosting M', $allNames, true) && in_array('Beratung vor Ort', $allNames, true) && count($allNames) === 10, $allNames);
$hostP = current(array_filter($allProducts, static fn ($x) => $x['id'] === $pHost));
check('Portal-Produkt: nur freigegebene Felder', array_diff(array_keys($hostP), ['id', 'name', 'description', 'type', 'price', 'taxRate', 'unit', 'intervalUnit', 'setupFee', 'minQuantity']) === [] && !isset($hostP['active'], $hostP['categoryId'], $hostP['sortOrder']) && $hostP['intervalUnit'] === 'MONTHLY' && $hostP['setupFee'] == 19, array_keys($hostP));

$before = count(mails());
$res = call('POST', '/api/portal/orders', ['productId' => $pWeb, 'quantity' => 1, 'note' => 'Bitte mit Blog und Kontaktformular'], null, $OP);
expect('Kunde bestellt Webseitenerstellung', $res, 201);
$o1 = $res[1];
check('Bestellung: Nummer BE-JJJJ-0001, Status „Eingegangen“, Schnappschuss, Summen', $o1['number'] === "BE-$year-0001" && $o1['status'] === 'PENDING' && $o1['productName'] === 'Webseitenerstellung einmalig' && $o1['unitPrice'] == 1500 && $o1['totals']['net'] == 1500 && $o1['totals']['tax'] == 285 && $o1['totals']['gross'] == 1785 && $o1['note'] === 'Bitte mit Blog und Kontaktformular', $o1);
check('Bestellung im Portal: nur freigegebene Felder', array_diff(array_keys($o1), ['id', 'number', 'status', 'createdAt', 'decidedAt', 'productName', 'productType', 'unitPrice', 'taxRate', 'quantity', 'unit', 'intervalUnit', 'setupFee', 'note', 'rejectReason', 'totals']) === [] && !isset($o1['clientId'], $o1['invoiceId'], $o1['recurringId'], $o1['projectId'], $o1['source']), array_keys($o1));
$all = mails();
$recent = array_slice($all, $before);
$subjects = array_map(static fn ($m) => $m['head'], $recent);
check('E-Mails: Benachrichtigung an die Firma und Eingangsbestätigung an den Kunden', count($recent) === 2 && str_contains(implode("\n", $subjects), 'X-Envelope-To: hallo@ralph-design.de') && str_contains(implode("\n", $subjects), 'X-Envelope-To: bea@besteller.de') && str_contains(mailText($recent[0]) . mailText($recent[1]), 'Bitte mit Blog und Kontaktformular') && str_contains(mailText($recent[0]) . mailText($recent[1]), 'in Kürze'), count($recent));

$res = call('POST', '/api/portal/orders', ['productId' => $pFlyer, 'quantity' => 50], null, $OP);
check('Unter der Mindestmenge → 400', $res[0] === 400 && str_contains($res[1]['error'], 'Mindestmenge: 100'), $res[2]);
$res = call('POST', '/api/portal/orders', ['productId' => $pFlyer, 'quantity' => 500], null, $OP);
check('Druckauftrag: 500 Stück × 0,12 € = 60,00 € netto', $res[0] === 201 && $res[1]['totals']['net'] == 60 && $res[1]['totals']['gross'] == 71.4 && $res[1]['unit'] === 'Stück', $res[2]);
$oFlyer = $res[1];
$res = call('POST', '/api/portal/orders', ['productId' => $pHost], null, $OP);
check('Mietprodukt: Menge 1 als Standard, erste Zahlung inkl. Einrichtung (28,90 € netto, 34,39 € brutto)', $res[0] === 201 && $res[1]['quantity'] == 1 && $res[1]['totals']['recurringNet'] == 9.9 && $res[1]['totals']['setupNet'] == 19 && $res[1]['totals']['net'] == 28.9 && $res[1]['totals']['gross'] == 34.39 && $res[1]['intervalUnit'] === 'MONTHLY', $res[2]);
$oHost = $res[1];
$res = call('POST', '/api/portal/orders', ['productId' => $pHours, 'quantity' => 20, 'note' => 'Relaunch der Unterseiten'], null, $OP);
check('Zeitprodukt: 20 Std. × 85 € = 1.700,00 € netto (Schätzung)', $res[0] === 201 && $res[1]['totals']['net'] == 1700 && $res[1]['unit'] === 'Std.', $res[2]);
$oHours = $res[1];
$oHost2 = call('POST', '/api/portal/orders', ['productId' => $pDomain, 'quantity' => 2], null, $OP)[1];
expect('Inaktives Produkt bestellen → 404', call('POST', '/api/portal/orders', ['productId' => $pInactive], null, $OP), 404);
expect('Produkt einer verborgenen Kategorie bestellen → 404', call('POST', '/api/portal/orders', ['productId' => $pHiddenCat], null, $OP), 404);
expect('Unbekanntes Produkt bestellen → 404', call('POST', '/api/portal/orders', ['productId' => 'gibtsnicht'], null, $OP), 404);
expect('Menge 0 → 400', call('POST', '/api/portal/orders', ['productId' => $pWeb, 'quantity' => 0], null, $OP), 400);
expect('Negative Menge → 400', call('POST', '/api/portal/orders', ['productId' => $pWeb, 'quantity' => -3], null, $OP), 400);
expect('Unsinnig große Menge → 400', call('POST', '/api/portal/orders', ['productId' => $pWeb, 'quantity' => 1000000], null, $OP), 400);
expect('Zu lange Anmerkung → 400', call('POST', '/api/portal/orders', ['productId' => $pWeb, 'note' => str_repeat('x', 2001)], null, $OP), 400);
expect('Bestellen ohne Schlüssel → 401', call('POST', '/api/portal/orders', ['productId' => $pWeb]), 401);

$res = call('GET', '/api/portal/orders', null, null, $OP);
check('Kunde sieht seine 5 Bestellungen, neueste zuerst', $res[0] === 200 && count($res[1]) === 5 && $res[1][0]['id'] === $oHost2['id'] && $res[1][4]['id'] === $o1['id'], array_column($res[1], 'number'));
$res = call('GET', '/api/portal/orders', null, null, $strangerP);
check('Mandantentrennung: der andere Kunde sieht diese Bestellungen nicht', $res[0] === 200 && count($res[1]) === 0, $res[2]);
expect('Mandantentrennung: fremde Bestellung stornieren → 404', call('POST', "/api/portal/orders/{$o1['id']}/cancel", [], null, $strangerP), 404);
$res = call('POST', "/api/portal/orders/{$oHost2['id']}/cancel", [], null, $OP);
check('Kunde storniert eine offene Bestellung', $res[0] === 200 && $res[1]['status'] === 'CANCELLED' && $res[1]['decidedAt'] !== null, $res[2]);
expect('Zweites Stornieren → 409', call('POST', "/api/portal/orders/{$oHost2['id']}/cancel", [], null, $OP), 409);
expect('Stornierte Bestellung kann nicht angenommen werden → 409', call('POST', "/api/orders/{$oHost2['id']}/accept", [], $token), 409);

// Mitarbeiter-Sicht
$res = call('GET', '/api/orders', null, $token);
check('Mitarbeiter: Bestellliste mit Kunde und Summen, offene zuerst', $res[0] === 200 && $res[1]['meta']['total'] === 5 && $res[1]['items'][0]['status'] === 'PENDING' && $res[1]['items'][4]['status'] === 'CANCELLED' && $res[1]['items'][0]['client']['company'] === 'Besteller KG' && isset($res[1]['items'][0]['totals']['gross']), array_column($res[1]['items'], 'status'));
$res = call('GET', '/api/orders?status=PENDING', null, $token);
check('Filter nach Status', $res[1]['meta']['total'] === 4, $res[2]);
$res = call('GET', '/api/settings', null, $token);
check('Einstellungen melden 4 offene Bestellungen', $res[1]['pendingOrders'] === 4, $res[2]);
expect('Bestellungen ohne Login → 401', call('GET', '/api/orders'), 401);

// Preisschnappschuss
$res = call('PATCH', "/api/products/$pWeb", ['price' => 2000], $token);
$res = call('GET', "/api/orders/{$o1['id']}", null, $token);
check('Preisänderung am Produkt ändert bestehende Bestellungen nicht', $res[1]['unitPrice'] == 1500 && $res[1]['productName'] === 'Webseitenerstellung einmalig', $res[2]);

// Annahme: Einmalprodukt → Rechnung
$before = count(mails());
$res = call('POST', "/api/orders/{$o1['id']}/accept", [], $token);
expect('Einmalprodukt-Bestellung annehmen', $res, 200);
$acc = $res[1];
check('Angenommen: Rechnung verknüpft, kein Abo, kein Projekt', $acc['status'] === 'ACCEPTED' && $acc['invoice']['number'] !== null && $acc['recurring'] === null && $acc['project'] === null && $acc['decidedAt'] !== null, $acc);
$res = call('GET', "/api/invoices/{$acc['invoice']['id']}", null, $token);
check('Rechnung: Entwurf, Position zum Bestellpreis (1.500 €, nicht 2.000 €), 19 %, Hinweis auf die Bestellung', $res[1]['status'] === 'DRAFT' && count($res[1]['items']) === 1 && $res[1]['items'][0]['unitPrice'] == 1500 && $res[1]['items'][0]['description'] === 'Webseitenerstellung einmalig' && $res[1]['taxRate'] == 19 && str_contains($res[1]['notes'], $o1['number']) && $res[1]['totals']['total'] == 1785 && $res[1]['dueDate'] !== null && $res[1]['clientId'] === $orderClient, $res[2]);
$recent = array_slice(mails(), $before);
check('Kunde erhält die Bestätigung per E-Mail', count($recent) === 1 && str_contains($recent[0]['head'], 'X-Envelope-To: bea@besteller.de') && str_contains(mailText($recent[0]), 'bestätigt'), count($recent));
expect('Zweite Annahme → 409', call('POST', "/api/orders/{$o1['id']}/accept", [], $token), 409);
expect('Ablehnen nach Annahme → 409', call('POST', "/api/orders/{$o1['id']}/reject", ['reason' => 'x'], $token), 409);
$res = call('GET', '/api/portal/orders', null, null, $OP);
$mine = current(array_filter($res[1], static fn ($o) => $o['id'] === $o1['id']));
check('Kunde sieht „Bestätigt“, ohne interne Verknüpfungen', $mine['status'] === 'ACCEPTED' && !isset($mine['invoiceId'], $mine['invoice']), $mine);
expect('Kunde kann bestätigte Bestellung nicht stornieren → 409', call('POST', "/api/portal/orders/{$o1['id']}/cancel", [], null, $OP), 409);

// Annahme: Mietprodukt → Abo + Einrichtungsrechnung + erste Rechnung
$res = call('POST', "/api/orders/{$oHost['id']}/accept", [], $token);
expect('Mietprodukt-Bestellung annehmen', $res, 200);
$accH = $res[1];
check('Miete: Abo, erste Rechnung und Einrichtungsrechnung verknüpft', $accH['recurring']['title'] === 'Webhosting M' && $accH['recurring']['active'] == 1 && $accH['invoice'] !== null && $accH['setupInvoice'] !== null && $accH['invoice']['id'] !== $accH['setupInvoice']['id'], $accH);
$rec = call('GET', "/api/recurring/{$accH['recurring']['id']}", null, $token)[1];
$todayStart = gmdate('Y-m-d') . 'T00:00:00.000Z';
check('Abo: monatlich, 9,90 € netto, Platzhalter im Text, Start heute, erste Abrechnung gelaufen (nächster Termin +1 Monat)', $rec['intervalUnit'] === 'MONTHLY' && $rec['clientId'] === $orderClient && $rec['startDate'] === $todayStart && $rec['occurrence'] === 1 && $rec['nextRunDate'] === App\Services\RecurringService::iso(App\Services\RecurringService::scheduleDate($todayStart, 'MONTHLY', 1)) && str_contains($rec['items'][0]['description'], '{zeitraum}') && $rec['items'][0]['unitPrice'] == 9.9 && $rec['totals']['total'] == 11.78 && $rec['autoSend'] === false, $rec);
$first = call('GET', "/api/invoices/{$accH['invoice']['id']}", null, $token)[1];
check('Erste Abo-Rechnung: Entwurf, Zeitraum eingesetzt, 11,78 € brutto, mit dem Abo verknüpft', $first['status'] === 'DRAFT' && preg_match('/^Webhosting M \(\d\d\.\d\d\.\d{4} – \d\d\.\d\d\.\d{4}\)$/u', $first['items'][0]['description']) === 1 && $first['totals']['total'] == 11.78 && $first['recurringId'] === $accH['recurring']['id'], $first['items'][0]['description'] ?? $first);
$setup = call('GET', "/api/invoices/{$accH['setupInvoice']['id']}", null, $token)[1];
check('Einrichtungsrechnung: „Einrichtung: Webhosting M“, 19 € netto, 22,61 € brutto, Entwurf', $setup['status'] === 'DRAFT' && $setup['items'][0]['description'] === 'Einrichtung: Webhosting M' && $setup['items'][0]['unitPrice'] == 19 && $setup['totals']['total'] == 22.61 && $setup['recurringId'] === null, $setup['items'] ?? $setup);

// Annahme: Miete mit Startdatum in der Zukunft, ohne sofortige Rechnung
$oFuture = call('POST', '/api/portal/orders', ['productId' => $pDomain, 'quantity' => 2], null, $OP)[1];
$res = call('POST', "/api/orders/{$oFuture['id']}/accept", ['startDate' => '2099-03-01', 'billNow' => true], $token);
$rec = call('GET', "/api/recurring/{$res[1]['recurring']['id']}", null, $token)[1];
check('Miete mit Startdatum in der Zukunft: Abo wartet, keine Rechnung, keine Einrichtung (Domain hat keine)', $res[0] === 200 && $res[1]['invoice'] === null && $res[1]['setupInvoice'] === null && $rec['occurrence'] === 0 && $rec['nextRunDate'] === '2099-03-01T00:00:00.000Z' && $rec['intervalUnit'] === 'YEARLY' && $rec['items'][0]['quantity'] == 2, $res[2]);

// Annahme: Miete mit automatischem Versand
$oAuto = call('POST', '/api/portal/orders', ['productId' => $pHost], null, $OP)[1];
$before = count(mails());
$res = call('POST', "/api/orders/{$oAuto['id']}/accept", ['autoSend' => true], $token);
$firstAuto = call('GET', "/api/invoices/{$res[1]['invoice']['id']}", null, $token)[1];
$newMails = array_slice(mails(), $before);
check('Miete mit „automatisch senden“: erste Rechnung geht sofort per E-Mail raus (Status Versendet)', $res[0] === 200 && $firstAuto['status'] === 'SENT' && count($firstAuto['emails']) === 1 && count(array_filter($newMails, static fn ($m) => str_contains($m['head'], 'X-Envelope-To: bea@besteller.de') && count(attachments($m)) === 1)) === 1, count($newMails));
$recAuto = call('GET', "/api/recurring/{$res[1]['recurring']['id']}", null, $token)[1];
check('… und das Abo versendet künftige Rechnungen automatisch', $recAuto['autoSend'] === true, $recAuto['autoSend']);

// Annahme: Zeitprodukt → Projekt
$res = call('POST', "/api/orders/{$oHours['id']}/accept", [], $token);
expect('Zeitprodukt-Bestellung annehmen', $res, 200);
check('Projekt verknüpft, keine Rechnung', $res[1]['project']['name'] === 'Projektarbeit nach Stunden' && $res[1]['invoice'] === null && $res[1]['recurring'] === null, $res[1]);
$proj = call('GET', "/api/projects/{$res[1]['project']['id']}", null, $token)[1];
check('Projekt: Stundensatz 85 €, Budget 1.700 € (20 Std.), geplant, Kunde, Bestellnummer und Anmerkung in der Beschreibung', $proj['hourlyRate'] == 85 && $proj['budget'] == 1700 && $proj['status'] === 'PLANNED' && $proj['clientId'] === $orderClient && str_contains($proj['description'], $oHours['number']) && str_contains($proj['description'], 'Relaunch der Unterseiten'), $proj);

// Ablehnen
$before = count(mails());
$res = call('POST', "/api/orders/{$oFlyer['id']}/reject", ['reason' => 'Wir drucken aktuell nur ab 1.000 Stück'], $token);
check('Bestellung ablehnen mit Begründung', $res[0] === 200 && $res[1]['status'] === 'REJECTED' && $res[1]['rejectReason'] === 'Wir drucken aktuell nur ab 1.000 Stück' && $res[1]['invoice'] === null, $res[2]);
$recent = array_slice(mails(), $before);
check('Kunde erhält die Ablehnung samt Grund per E-Mail', count($recent) === 1 && str_contains(mailText($recent[0]), 'Wir drucken aktuell nur ab 1.000 Stück') && str_contains(mailText($recent[0]), 'leider'), count($recent));
$mine = current(array_filter(call('GET', '/api/portal/orders', null, null, $OP)[1], static fn ($o) => $o['id'] === $oFlyer['id']));
check('Kunde sieht „Abgelehnt“ mit Begründung', $mine['status'] === 'REJECTED' && $mine['rejectReason'] === 'Wir drucken aktuell nur ab 1.000 Stück', $mine);
expect('Ablehnung ohne Grund ist erlaubt', call('POST', "/api/orders/" . call('POST', '/api/portal/orders', ['productId' => $pScript], null, $OP)[1]['id'] . "/reject", [], $token), 200);

// Bestellung im Auftrag des Kunden erfassen
$res = call('POST', '/api/orders', ['clientId' => $orderClient, 'productId' => $pInactive, 'quantity' => 3, 'note' => 'Telefonische Bestellung'], $token);
check('Mitarbeiter erfasst Bestellung für den Kunden (auch inaktives Produkt), Quelle „Admin“', $res[0] === 201 && $res[1]['source'] === 'ADMIN' && $res[1]['status'] === 'PENDING' && $res[1]['quantity'] == 3, $res[2]);
$adminOrder = $res[1];
expect('Bestellung für unbekannten Kunden → 404', call('POST', '/api/orders', ['clientId' => 'gibtsnicht', 'productId' => $pWeb], $token), 404);
expect('Bestellung für unbekanntes Produkt → 404', call('POST', '/api/orders', ['clientId' => $orderClient, 'productId' => 'gibtsnicht'], $token), 404);
expect('Bestellung unter Mindestmenge (Mitarbeiter) → 400', call('POST', '/api/orders', ['clientId' => $orderClient, 'productId' => $pFlyer, 'quantity' => 10], $token), 400);

// Produkt löschen: Bestellung bleibt bearbeitbar
$res = call('DELETE', "/api/products/$pInactive", null, $token);
$res2 = call('GET', "/api/orders/{$adminOrder['id']}", null, $token);
check('Produkt gelöscht: Bestellung behält Name und Preis, Verknüpfung entfällt', $res[0] === 204 && $res2[1]['productId'] === null && $res2[1]['productName'] === 'Auslaufprodukt' && $res2[1]['unitPrice'] == 10, $res2[2]);
$res = call('POST', "/api/orders/{$adminOrder['id']}/accept", [], $token);
check('… und lässt sich trotzdem annehmen', $res[0] === 200 && $res[1]['invoice'] !== null, $res[2]);
$res = call('GET', "/api/clients/$orderClient", null, $token);
$log = json_encode($res[1]['activities'], JSON_UNESCAPED_UNICODE);
check('Aktivitätsverlauf des Kunden enthält Bestell- und Entscheidungsereignisse', str_contains($log, 'angenommen') && str_contains($log, 'abgelehnt') && str_contains($log, 'über das Kundenportal') && str_contains($log, 'vom Kunden storniert'), count($res[1]['activities']));
$res = call('GET', '/api/settings', null, $token);
check('Keine offenen Bestellungen mehr', $res[1]['pendingOrders'] === 0, $res[2]);

// Schutz vor Bestell-Spam
$spamClient = call('POST', '/api/clients', ['name' => 'Spam Kunde', 'email' => 'spam@example.com'], $token)[1]['id'];
$SP = ['X-Portal-Token: ' . call('POST', "/api/clients/$spamClient/portal", [], $token)[1]['token']];
$codes = [];
for ($i = 0; $i < 21; $i++) {
    $codes[] = call('POST', '/api/portal/orders', ['productId' => $pScript], null, $SP)[0];
}
check('Mehr als 20 offene Bestellungen gleichzeitig werden abgelehnt (409)', count(array_filter($codes, static fn ($c) => $c === 201)) === 20 && end($codes) === 409, array_count_values($codes));
call('DELETE', "/api/clients/$spamClient", null, $token);
call('DELETE', "/api/clients/$strangerClient", null, $token);

echo "Kundenkonten (Registrierung)\n";
$linkFrom = static function (array $mail, string $kind): ?string {
    return preg_match('~https://crm\.example\.com/portal#' . $kind . '=([A-Za-z0-9_-]+)~', mailText($mail), $m) ? $m[1] : null;
};
$mailTo = static function (string $address) {
    $hits = array_values(array_filter(mails(), static fn ($m) => str_contains($m['head'], "X-Envelope-To: $address")));
    return $hits === [] ? null : end($hits);
};
$accountDb = new PDO('sqlite:' . "$tmp/test.db");
$res = call('GET', '/api/portal/config');
check('Portal-Konfiguration (öffentlich): Registrierung offen, Firmenname, Datenschutz-Link, Mindestlänge', $res[0] === 200 && $res[1]['registration'] === true && $res[1]['company'] === 'Ralph Design' && $res[1]['privacyUrl'] === 'https://crm.example.com/datenschutz' && $res[1]['termsUrl'] === null && $res[1]['minPassword'] === 10, $res[2]);
check('Registrierung ist geschlossen, wenn kein Mailserver eingerichtet ist oder sie abgeschaltet wurde', App\Services\AccountService::registrationOpen() === false && (putenv('SMTP_HOST=127.0.0.1') || true) && App\Services\AccountService::registrationOpen() === true && (putenv('PORTAL_REGISTRATION=off') || true) && App\Services\AccountService::registrationOpen() === false && (putenv('PORTAL_REGISTRATION') || true) && (putenv('SMTP_HOST') || true));

$register = static fn (array $body) => call('POST', '/api/portal/register', $body + ['terms' => true]);
expect('Registrierung mit ungültiger E-Mail → 400', $register(['name' => 'Max Muster', 'email' => 'kaputt']), 400);
expect('Registrierung mit zu kurzem Namen → 400', $register(['name' => 'M', 'email' => 'max@muster.example']), 400);
expect('Registrierung ohne Zustimmung zur Datenschutzerklärung → 400', call('POST', '/api/portal/register', ['name' => 'Max Muster', 'email' => 'max@muster.example']), 400);
$clientsBefore = (int) $accountDb->query('SELECT COUNT(*) FROM Client')->fetchColumn();
$before = count(mails());
$res = $register(['name' => 'Max Muster', 'company' => 'Muster GmbH', 'email' => 'Max@Muster.example']);
check('Registrierung: 202 mit neutraler Meldung', $res[0] === 202 && $res[1]['ok'] === true && str_contains($res[1]['message'], 'E-Mail'), $res[2]);
$mail = $mailTo('max@muster.example');
$verifyToken = $mail ? $linkFrom($mail, 'verify') : null;
check('Bestätigungs-Mail an die (kleingeschriebene) Adresse mit Link', count(mails()) === $before + 1 && $verifyToken !== null && strlen($verifyToken) === 43 && str_contains(mailText($mail), 'Passwort fest') && !str_contains(mailText($mail), 'Passwort:'), $mail ? mailText($mail) : 'keine Mail');
check('Noch kein Kunde angelegt, Konto unbestätigt', (int) $accountDb->query('SELECT COUNT(*) FROM Client')->fetchColumn() === $clientsBefore && $accountDb->query("SELECT verifiedAt FROM PortalAccount WHERE email = 'max@muster.example'")->fetchColumn() === null);
check('Datenbank speichert nur den Hash des Bestätigungsschlüssels', $accountDb->query("SELECT verifyTokenHash FROM PortalAccount WHERE email = 'max@muster.example'")->fetchColumn() === hash('sha256', $verifyToken));
expect('Anmeldung vor der Bestätigung unmöglich (kein Passwort bekannt) → 401', call('POST', '/api/portal/login', ['email' => 'max@muster.example', 'password' => 'irgendetwas123']), 401);

$before = count(mails());
$res = call('POST', '/api/portal/register', ['name' => 'Bot', 'email' => 'bot@spam.example', 'terms' => true, 'website' => 'http://spam.example']);
check('Bot-Falle: gleiche Antwort, aber keine Mail und kein Konto', $res[0] === 202 && count(mails()) === $before && (int) $accountDb->query("SELECT COUNT(*) FROM PortalAccount WHERE email = 'bot@spam.example'")->fetchColumn() === 0, $res[2]);

$res = call('POST', '/api/portal/verify-info', ['token' => $verifyToken]);
check('Bestätigungsseite wird mit Name, Firma und Adresse vorbelegt', $res[0] === 200 && $res[1] === ['name' => 'Max Muster', 'company' => 'Muster GmbH', 'email' => 'max@muster.example'], $res[2]);
expect('Ungültiger Bestätigungslink → 400', call('POST', '/api/portal/verify-info', ['token' => str_repeat('a', 43)]), 400);
expect('Zu kurzes Passwort → 400', call('POST', '/api/portal/verify', ['token' => $verifyToken, 'password' => 'kurz123']), 400);
expect('Passwort gleich E-Mail-Adresse → 400', call('POST', '/api/portal/verify', ['token' => $verifyToken, 'password' => 'max@muster.example']), 400);
$before = count(mails());
$res = call('POST', '/api/portal/verify', ['token' => $verifyToken, 'password' => 'sehr-sicheres-passwort', 'name' => 'Maximilian Muster']);
check('Bestätigen + Passwort festlegen: angemeldet (Sitzung, 14 Tage)', $res[0] === 200 && strlen($res[1]['token']) === 43 && $res[1]['name'] === 'Maximilian Muster' && $res[1]['expiresAt'] > gmdate('Y-m-d\TH:i:s', strtotime('+13 days')), $res[2]);
$sessionToken = $res[1]['token'];
$S = ["X-Portal-Token: $sessionToken"];
$newClient = $accountDb->query("SELECT * FROM Client WHERE email = 'max@muster.example'")->fetch(PDO::FETCH_ASSOC);
check('Jetzt wurde der Kunde angelegt: Status Lead, Quelle „Portal-Registrierung“, Firma und geänderter Name', $newClient !== false && $newClient['status'] === 'LEAD' && $newClient['source'] === 'Portal-Registrierung' && $newClient['company'] === 'Muster GmbH' && $newClient['name'] === 'Maximilian Muster', $newClient);
$notify = array_values(array_filter(array_slice(mails(), $before), static fn ($m) => str_contains($m['head'], 'X-Envelope-To: hallo@ralph-design.de')));
check('Firma wird über die Registrierung per E-Mail informiert', count($notify) === 1 && str_contains(mailText($notify[0]), 'Muster GmbH') && str_contains(mailText($notify[0]), 'neuer Kunde'), count($notify));
expect('Bestätigungslink ist nur einmal nutzbar → 400', call('POST', '/api/portal/verify', ['token' => $verifyToken, 'password' => 'anderes-passwort-123']), 400);
$res = call('GET', '/api/portal/me', null, null, $S);
check('Sitzung funktioniert in allen Portal-Funktionen (Daten des neuen Kunden)', $res[0] === 200 && $res[1]['client']['name'] === 'Maximilian Muster' && $res[1]['summary']['open'] == 0, $res[2]);
expect('Sitzung sieht den Katalog', call('GET', '/api/portal/products', null, null, $S), 200);
$res = call('GET', "/api/clients/{$newClient['id']}/portal", null, $token);
check('Mitarbeiter sieht das Konto beim Kunden (bestätigt, aktiv)', $res[0] === 200 && count($res[1]['accounts']) === 1 && $res[1]['accounts'][0]['email'] === 'max@muster.example' && $res[1]['accounts'][0]['active'] == 1 && $res[1]['accounts'][0]['verifiedAt'] !== null && !isset($res[1]['accounts'][0]['passwordHash']), $res[2]);

// Anmelden und Abmelden
expect('Anmeldung mit falschem Passwort → 401', call('POST', '/api/portal/login', ['email' => 'max@muster.example', 'password' => 'falsch-falsch-falsch']), 401);
$res = call('POST', '/api/portal/login', ['email' => ' MAX@muster.EXAMPLE ', 'password' => 'sehr-sicheres-passwort']);
check('Anmeldung: Adresse ohne Beachtung von Groß-/Kleinschreibung und Leerzeichen', $res[0] === 200 && strlen($res[1]['token']) === 43 && $res[1]['token'] !== $sessionToken, $res[2]);
$S2 = ["X-Portal-Token: {$res[1]['token']}"];
$a = call('POST', '/api/portal/login', ['email' => 'unbekannt@muster.example', 'password' => 'sehr-sicheres-passwort']);
$b = call('POST', '/api/portal/login', ['email' => 'max@muster.example', 'password' => 'falsch-falsch-falsch']);
check('Unbekannte Adresse und falsches Passwort sind nicht zu unterscheiden', $a[0] === 401 && $b[0] === 401 && $a[1]['error'] === $b[1]['error'], [$a[2], $b[2]]);
expect('Abmelden', call('POST', '/api/portal/logout', [], null, $S2), 204);
expect('Nach dem Abmelden ist die Sitzung ungültig → 401', call('GET', '/api/portal/me', null, null, $S2), 401);
expect('Die andere Sitzung bleibt bestehen', call('GET', '/api/portal/me', null, null, $S), 200);

// Bestellen mit Konto, Kunde wird nach Annahme aktiv
$res = call('POST', '/api/portal/orders', ['productId' => $pWeb, 'quantity' => 1], null, $S);
expect('Kunde mit Konto bestellt', $res, 201);
$accOrder = $res[1];
call('POST', "/api/orders/{$accOrder['id']}/accept", [], $token);
check('Nach der ersten angenommenen Bestellung wird aus dem Lead ein aktiver Kunde', $accountDb->query("SELECT status FROM Client WHERE id = '{$newClient['id']}'")->fetchColumn() === 'ACTIVE');

// Sperren durch den Admin
$acctId = $accountDb->query("SELECT id FROM PortalAccount WHERE email = 'max@muster.example'")->fetchColumn();
expect('Konto sperren: unbekanntes Konto → 404', call('POST', '/api/portal-accounts/gibtsnicht/active', ['active' => false], $token), 404);
expect('Konto sperren braucht Login → 401', call('POST', "/api/portal-accounts/$acctId/active", ['active' => false]), 401);
expect('Konto sperren', call('POST', "/api/portal-accounts/$acctId/active", ['active' => false], $token), 200);
expect('Gesperrt: bestehende Sitzung endet sofort → 401', call('GET', '/api/portal/me', null, null, $S), 401);
$res = call('POST', '/api/portal/login', ['email' => 'max@muster.example', 'password' => 'sehr-sicheres-passwort']);
check('Gesperrt: Anmeldung verweigert (403) mit verständlicher Meldung', $res[0] === 403 && str_contains($res[1]['error'], 'gesperrt'), $res[2]);
call('POST', "/api/portal-accounts/$acctId/active", ['active' => true], $token);
$res = call('POST', '/api/portal/login', ['email' => 'max@muster.example', 'password' => 'sehr-sicheres-passwort']);
check('Entsperrt: Anmeldung klappt wieder', $res[0] === 200, $res[2]);
$S3 = ["X-Portal-Token: {$res[1]['token']}"];
call('DELETE', "/api/clients/{$newClient['id']}/portal", null, $token);
expect('„Zugang sperren“ beim Kunden beendet auch angemeldete Sitzungen → 401', call('GET', '/api/portal/me', null, null, $S3), 401);

// Passwort vergessen
$before = count(mails());
$res = call('POST', '/api/portal/forgot', ['email' => 'unbekannt@muster.example']);
check('Passwort vergessen (unbekannte Adresse): gleiche neutrale Antwort, keine Mail', $res[0] === 202 && $res[1]['ok'] === true && count(mails()) === $before, $res[2]);
$res = call('POST', '/api/portal/forgot', ['email' => 'max@muster.example']);
$mail = $mailTo('max@muster.example');
$resetToken = $mail ? $linkFrom($mail, 'reset') : null;
check('Passwort vergessen (bekannte Adresse): gleiche Antwort + Mail mit Zurücksetzen-Link', $res[0] === 202 && count(mails()) === $before + 1 && $resetToken !== null && str_contains(mailText($mail), 'zwei Stunden'), $res[2]);
$res = call('POST', '/api/portal/login', ['email' => 'max@muster.example', 'password' => 'sehr-sicheres-passwort']);
$S4 = ["X-Portal-Token: {$res[1]['token']}"];
expect('Zurücksetzen mit zu kurzem Passwort → 400', call('POST', '/api/portal/reset', ['token' => $resetToken, 'password' => 'kurz']), 400);
expect('Zurücksetzen mit ungültigem Link → 400', call('POST', '/api/portal/reset', ['token' => str_repeat('b', 43), 'password' => 'neues-passwort-123']), 400);
expect('Passwort zurücksetzen', call('POST', '/api/portal/reset', ['token' => $resetToken, 'password' => 'neues-passwort-123']), 200);
expect('Zurücksetzen beendet alle bisherigen Sitzungen → 401', call('GET', '/api/portal/me', null, null, $S4), 401);
expect('Altes Passwort gilt nicht mehr → 401', call('POST', '/api/portal/login', ['email' => 'max@muster.example', 'password' => 'sehr-sicheres-passwort']), 401);
$res = call('POST', '/api/portal/login', ['email' => 'max@muster.example', 'password' => 'neues-passwort-123']);
expect('Neues Passwort funktioniert', $res, 200);
$S5 = ["X-Portal-Token: {$res[1]['token']}"];
expect('Zurücksetzen-Link nur einmal nutzbar → 400', call('POST', '/api/portal/reset', ['token' => $resetToken, 'password' => 'noch-ein-passwort-1']), 400);
call('POST', '/api/portal/forgot', ['email' => 'max@muster.example']);
$accountDb->exec("UPDATE PortalAccount SET resetExpiresAt = '2020-01-01T00:00:00.000Z' WHERE email = 'max@muster.example'");
$expiredToken = $linkFrom($mailTo('max@muster.example'), 'reset');
expect('Abgelaufener Zurücksetzen-Link → 400', call('POST', '/api/portal/reset', ['token' => $expiredToken, 'password' => 'noch-ein-passwort-1']), 400);

// Passwort ändern
$res = call('POST', '/api/portal/login', ['email' => 'max@muster.example', 'password' => 'neues-passwort-123']);
$S6 = ["X-Portal-Token: {$res[1]['token']}"];
expect('Passwort ändern mit falschem aktuellem Passwort → 400', call('POST', '/api/portal/password', ['current' => 'falsch-falsch-1', 'password' => 'drittes-passwort-123'], null, $S6), 400);
expect('Passwort ändern: neues zu kurz → 400', call('POST', '/api/portal/password', ['current' => 'neues-passwort-123', 'password' => 'kurz'], null, $S6), 400);
expect('Passwort ändern', call('POST', '/api/portal/password', ['current' => 'neues-passwort-123', 'password' => 'drittes-passwort-123'], null, $S6), 200);
expect('Die aktuelle Sitzung bleibt nach dem Ändern bestehen', call('GET', '/api/portal/me', null, null, $S6), 200);
expect('Andere Sitzungen enden nach dem Ändern → 401', call('GET', '/api/portal/me', null, null, $S5), 401);
$res = call('POST', '/api/portal/password', ['current' => 'x', 'password' => 'viertes-passwort-123'], null, ["X-Portal-Token: {$oAccess['token']}"]);
check('Passwort ändern mit Zugangslink (ohne Konto) nicht möglich', in_array($res[0], [401, 403], true), $res[2]);

// Bestehender Kunde wird verknüpft
$res = call('POST', '/api/clients', ['name' => 'Erna Altkunde', 'company' => 'Altkunde AG', 'email' => 'erna@altkunde.example'], $token);
$oldClient = $res[1]['id'];
$res = call('POST', '/api/invoices', ['clientId' => $oldClient, 'status' => 'SENT', 'items' => [['description' => 'Alte Rechnung', 'unitPrice' => 100]]], $token);
$clientsBefore = (int) $accountDb->query('SELECT COUNT(*) FROM Client')->fetchColumn();
$register(['name' => 'Erna', 'email' => 'ERNA@altkunde.example']);
$tok = $linkFrom($mailTo('erna@altkunde.example'), 'verify');
$res = call('POST', '/api/portal/verify', ['token' => $tok, 'password' => 'ernas-passwort-123']);
$E = ["X-Portal-Token: {$res[1]['token']}"];
check('Gleiche E-Mail wie ein bestehender Kunde: Konto wird mit ihm verknüpft (kein Duplikat)', $res[0] === 200 && (int) $accountDb->query('SELECT COUNT(*) FROM Client')->fetchColumn() === $clientsBefore && $accountDb->query("SELECT clientId FROM PortalAccount WHERE email = 'erna@altkunde.example'")->fetchColumn() === $oldClient, $res[2]);
$res = call('GET', '/api/portal/invoices', null, null, $E);
check('… und sie sieht die Rechnungen des bestehenden Kunden', count($res[1]) === 1 && $res[1][0]['items'][0]['description'] === 'Alte Rechnung', $res[2]);
$log = json_encode(call('GET', "/api/clients/$oldClient", null, $token)[1]['activities'], JSON_UNESCAPED_UNICODE);
check('Aktivitätsprotokoll: „mit bestehendem Kunden verknüpft“', str_contains($log, 'mit bestehendem Kunden verknüpft'), $log);

// Doppelte Registrierung
$before = count(mails());
$res = $register(['name' => 'Erna Zweitversuch', 'email' => 'erna@altkunde.example']);
$mail = $mailTo('erna@altkunde.example');
check('Erneute Registrierung einer bekannten Adresse: gleiche Antwort, Mail „Konto besteht bereits“, kein zweites Konto', $res[0] === 202 && count(mails()) === $before + 1 && str_contains(mailText($mail), 'bereits ein Konto') && (int) $accountDb->query("SELECT COUNT(*) FROM PortalAccount WHERE email = 'erna@altkunde.example'")->fetchColumn() === 1, $res[2]);

// Unbestätigte Registrierung wiederholen
$register(['name' => 'Uwe Unsicher', 'email' => 'uwe@unsicher.example']);
$first = $linkFrom($mailTo('uwe@unsicher.example'), 'verify');
$register(['name' => 'Anderer Name', 'email' => 'uwe@unsicher.example']);
$second = $linkFrom($mailTo('uwe@unsicher.example'), 'verify');
check('Zweite Registrierung vor dem Bestätigen: neuer Link, alter wird ungültig, Daten bleiben', $first !== $second && call('POST', '/api/portal/verify-info', ['token' => $first])[0] === 400 && call('POST', '/api/portal/verify-info', ['token' => $second])[1]['name'] === 'Uwe Unsicher');

// Sperre gegen Durchprobieren und Massenregistrierung
$lock = [];
for ($i = 0; $i < 6; $i++) {
    $lock[] = call('POST', '/api/portal/login', ['email' => 'erna@altkunde.example', 'password' => "falsch-$i-falsch"])[0];
}
$res = call('POST', '/api/portal/login', ['email' => 'erna@altkunde.example', 'password' => 'ernas-passwort-123']);
check('Nach 5 Fehlversuchen wird die Anmeldung dieses Kontos gebremst (429), selbst mit richtigem Passwort', $lock[4] === 401 && $res[0] === 429, [$lock, $res[2]]);
$codes = [];
for ($i = 0; $i < 6; $i++) {
    $codes[] = $register(['name' => "Massen $i", 'email' => "massen$i@spam.example"])[0];
}
check('Registrierungen pro IP und Stunde sind begrenzt (429)', in_array(429, $codes, true) && $codes[0] === 202, $codes);
$accountDb->exec("DELETE FROM PortalAccount WHERE email LIKE '%@spam.example'");

echo "Kundenportal\n";
$portalDb = new PDO('sqlite:' . "$tmp/test.db");
$res = call('GET', "/api/clients/$clientId/portal", null, $token);
check('Portal: anfangs kein Zugang', $res[0] === 200 && $res[1]['active'] === false && $res[1]['mailConfigured'] === true && $res[1]['recipient'] === 'anna@beispiel.de', $res[2]);
expect('Portal-Zugang für unbekannten Kunden → 404', call('POST', '/api/clients/gibtsnicht/portal', [], $token), 404);
expect('Portal-Verwaltung braucht Login → 401', call('POST', "/api/clients/$clientId/portal", []), 401);
$res = call('POST', "/api/clients/$clientId/portal", [], $token);
expect('Portal-Zugang erstellen', $res, 201);
$portalToken = $res[1]['token'];
check('Link: feste Adresse (APP_URL), Schlüssel im Fragment, ca. 1 Jahr gültig', strlen($portalToken) === 43 && $res[1]['link'] === "https://crm.example.com/portal#$portalToken" && $res[1]['emailed'] === false && $res[1]['expiresAt'] > gmdate('Y-m-d\TH:i:s', strtotime('+360 days')), $res[1]);
$hash = $portalDb->query('SELECT tokenHash FROM PortalToken ORDER BY createdAt DESC LIMIT 1')->fetchColumn();
check('Datenbank speichert nur den SHA-256-Hash, nie den Schlüssel', $hash === hash('sha256', $portalToken) && $hash !== $portalToken && !str_contains((string) json_encode($portalDb->query('SELECT * FROM PortalToken')->fetchAll(PDO::FETCH_ASSOC)), $portalToken));
$P = ["X-Portal-Token: $portalToken"];
expect('Portal ohne Schlüssel → 401', call('GET', '/api/portal/me'), 401);
expect('Portal mit falschem Schlüssel → 401', call('GET', '/api/portal/me', null, null, ['X-Portal-Token: ' . str_repeat('a', 43)]), 401);
expect('Portal akzeptiert kein Mitarbeiter-Token als Schlüssel → 401', call('GET', '/api/portal/me', null, null, ["X-Portal-Token: $token"]), 401);
expect('Mitarbeiter-API akzeptiert den Portal-Schlüssel nicht → 401', call('GET', '/api/clients', null, $portalToken), 401);

[$portalOverdueId] = $mk('2020-06-01T00:00:00Z', 'SENT', 250);
$res = call('GET', '/api/portal/me', null, null, $P);
check('Portal: Name, Firmendaten (Bank), Offen/Überfällig', $res[0] === 200 && $res[1]['client']['name'] === 'Anna Beispiel' && $res[1]['client']['company'] === 'Beispiel GmbH' && $res[1]['company']['name'] === 'Ralph Design' && $res[1]['company']['iban'] === 'DE89 3704 0044 0532 0130 00' && $res[1]['summary']['open'] > 0 && $res[1]['summary']['overdue'] > 0 && !isset($res[1]['client']['email']), $res[2]);
$res = call('GET', '/api/portal/invoices', null, null, $P);
$portalInvoices = $res[1];
$admin = call('GET', "/api/invoices?clientId=$clientId&pageSize=100", null, $token)[1]['items'];
$visible = array_values(array_filter($admin, static fn ($i) => in_array($i['status'], ['SENT', 'OVERDUE', 'PAID'], true)));
$ids1 = array_column($portalInvoices, 'number'); sort($ids1);
$ids2 = array_column($visible, 'number'); sort($ids2);
check('Portal zeigt genau die versendeten/überfälligen/bezahlten Rechnungen (keine Entwürfe, keine Stornos)', $res[0] === 200 && $ids1 === $ids2 && count($ids1) > 3 && array_diff(array_column($portalInvoices, 'status'), ['SENT', 'OVERDUE', 'PAID']) === [], [$ids1, $ids2]);
$draftInvoice = current(array_filter($admin, static fn ($i) => $i['status'] === 'DRAFT'));
check('Es gibt Entwurfs-Rechnungen, die der Kunde nicht sieht', $draftInvoice !== false && !in_array($draftInvoice['number'], $ids1, true));
$first = $portalInvoices[0];
check('Portal-Rechnung enthält nur freigegebene Felder', array_diff(array_keys($first), ['id', 'number', 'status', 'issueDate', 'dueDate', 'currency', 'taxRate', 'discount', 'notes', 'items', 'payments', 'totals']) === [] && !isset($first['clientId'], $first['projectId'], $first['recurringId'], $first['emails'], $first['reminders']) && isset($first['totals']['balance']), array_keys($first));
$overdueRow = current(array_filter($portalInvoices, static fn ($i) => $i['status'] === 'OVERDUE'));
check('Überfällige Rechnung wird als OVERDUE angezeigt', $overdueRow !== false && $overdueRow['totals']['balance'] > 0);

$res = call('GET', "/api/portal/invoices/{$first['id']}/pdf", null, null, $P);
check('Portal: Rechnungs-PDF herunterladen', $res[0] === 200 && str_starts_with($res[2], '%PDF') && str_contains(pdfText($res[2]), $first['number']), substr($res[2], 0, 60));
expect('Portal: PDF einer Entwurfs-Rechnung → 404', call('GET', "/api/portal/invoices/{$draftInvoice['id']}/pdf", null, null, $P), 404);
expect('Portal: PDF ohne Schlüssel → 401', call('GET', "/api/portal/invoices/{$first['id']}/pdf"), 401);

// Mandantentrennung: Daten eines anderen Kunden
$res = call('POST', '/api/clients', ['name' => 'Fremder Kunde', 'email' => 'fremd@example.com'], $token);
$otherClient = $res[1]['id'];
$res = call('POST', '/api/invoices', ['clientId' => $otherClient, 'status' => 'SENT', 'items' => [['description' => 'Geheim', 'unitPrice' => 999]]], $token);
$otherInvoiceId = $res[1]['id'];
$res = call('POST', '/api/quotes', ['clientId' => $otherClient, 'status' => 'SENT', 'items' => [['description' => 'Geheim', 'unitPrice' => 999]]], $token);
$otherQuoteId = $res[1]['id'];
expect('Mandantentrennung: fremde Rechnung (PDF) → 404', call('GET', "/api/portal/invoices/$otherInvoiceId/pdf", null, null, $P), 404);
expect('Mandantentrennung: fremdes Angebot (PDF) → 404', call('GET', "/api/portal/quotes/$otherQuoteId/pdf", null, null, $P), 404);
expect('Mandantentrennung: fremdes Angebot annehmen → 404', call('POST', "/api/portal/quotes/$otherQuoteId/accept", [], null, $P), 404);
check('Mandantentrennung: fremde Daten tauchen in Listen nicht auf', !in_array($otherInvoiceId, array_column(call('GET', '/api/portal/invoices', null, null, $P)[1], 'id'), true) && !in_array($otherQuoteId, array_column(call('GET', '/api/portal/quotes', null, null, $P)[1], 'id'), true));
$otherAccess = call('POST', "/api/clients/$otherClient/portal", [], $token)[1];
$res = call('GET', '/api/portal/invoices', null, null, ["X-Portal-Token: {$otherAccess['token']}"]);
check('Der andere Kunde sieht nur seine eigene Rechnung', count($res[1]) === 1 && $res[1][0]['id'] === $otherInvoiceId, $res[2]);

// Angebote beantworten
$mkQuote = static function (string $status, ?string $valid = null) use ($clientId, $token) {
    $res = call('POST', '/api/quotes', array_filter(['clientId' => $clientId, 'status' => $status, 'validUntil' => $valid, 'items' => [['description' => 'Relaunch', 'quantity' => 2, 'unitPrice' => 500]]]), $token);
    return $res[1];
};
$draftQuote = $mkQuote('DRAFT');
$sentQuote = $mkQuote('SENT');
$expiredQuote = $mkQuote('SENT', '2020-01-01');
$res = call('GET', '/api/portal/quotes', null, null, $P);
$quoteNumbers = array_column($res[1], 'number');
check('Portal-Angebote: ohne Entwürfe, mit Summen', $res[0] === 200 && !in_array($draftQuote['number'], $quoteNumbers, true) && in_array($sentQuote['number'], $quoteNumbers, true) && isset($res[1][0]['totals']['total']) && !isset($res[1][0]['clientId'], $res[1][0]['invoiceId']), $quoteNumbers);
expect('Portal: Angebots-PDF', call('GET', "/api/portal/quotes/{$sentQuote['id']}/pdf", null, null, $P), 200);
expect('Portal: Entwurfs-Angebot nicht abrufbar → 404', call('GET', "/api/portal/quotes/{$draftQuote['id']}/pdf", null, null, $P), 404);
expect('Portal: Entwurfs-Angebot nicht annehmbar → 404', call('POST', "/api/portal/quotes/{$draftQuote['id']}/accept", [], null, $P), 404);
$res = call('POST', "/api/portal/quotes/{$expiredQuote['id']}/accept", [], null, $P);
check('Portal: abgelaufenes Angebot nicht annehmbar → 409', $res[0] === 409 && str_contains($res[1]['error'], 'abgelaufen'), $res[2]);
$before = count(mails());
$res = call('POST', "/api/portal/quotes/{$sentQuote['id']}/accept", [], null, $P);
check('Kunde nimmt Angebot im Portal an', $res[0] === 200 && $res[1]['status'] === 'ACCEPTED' && $res[1]['respondedAt'] !== null, $res[2]);
$res = call('GET', "/api/quotes/{$sentQuote['id']}", null, $token);
check('Mitarbeiter sieht „Angenommen“ im System', $res[1]['status'] === 'ACCEPTED' && $res[1]['respondedAt'] !== null, $res[2]);
$all = mails();
check('Benachrichtigung an die Firma per E-Mail', count($all) === $before + 1 && str_contains(end($all)['head'], 'X-Envelope-To: hallo@ralph-design.de') && str_contains(mailText(end($all)), 'angenommen'), mailText(end($all)));
expect('Zweite Antwort auf dasselbe Angebot → 409', call('POST', "/api/portal/quotes/{$sentQuote['id']}/decline", [], null, $P), 409);
$sent2 = $mkQuote('SENT');
$res = call('POST', "/api/portal/quotes/{$sent2['id']}/decline", [], null, $P);
check('Kunde lehnt Angebot ab', $res[0] === 200 && $res[1]['status'] === 'DECLINED', $res[2]);
$res = call('GET', '/api/dashboard/summary', null, $token);
check('Aktivitätsprotokoll hält die Kundenantwort fest', str_contains(json_encode($res[1]['activities']), 'im Portal'), array_column($res[1]['activities'], 'message'));

// Versand des Zugangslinks per E-Mail
$before = count(mails());
$res = call('POST', "/api/clients/$clientId/portal", ['send' => true], $token);
$newToken = $res[1]['token'];
$all = mails();
check('Zugangslink per E-Mail an den Kunden gesendet, Link im Text', $res[0] === 201 && $res[1]['emailed'] === true && $res[1]['to'] === 'anna@beispiel.de' && count($all) === $before + 1 && str_contains(mailText(end($all)), "https://crm.example.com/portal#$newToken") && str_contains(mailText(end($all)), 'Kundenportal') && str_contains(end($all)['head'], 'X-Envelope-To: anna@beispiel.de'), mailText(end($all)));
expect('Neuer Link macht den alten ungültig → 401', call('GET', '/api/portal/me', null, null, $P), 401);
$P = ["X-Portal-Token: $newToken"];
expect('Neuer Link funktioniert', call('GET', '/api/portal/me', null, null, $P), 200);
$res = call('POST', "/api/clients/$clientId/portal", ['send' => true, 'to' => 'reject@example.com'], $token);
check('Mailfehler: Link wird trotzdem geliefert, Fehler gemeldet', $res[0] === 201 && $res[1]['emailed'] === false && str_contains((string) $res[1]['emailError'], '550') && strlen($res[1]['token']) === 43, $res[2]);
$P = ["X-Portal-Token: {$res[1]['token']}"];
$noMail = call('POST', '/api/clients', ['name' => 'Ohne Mail'], $token)[1]['id'];
expect('Ohne Empfänger und ohne Kunden-E-Mail nicht sendbar → 400', call('POST', "/api/clients/$noMail/portal", ['send' => true], $token), 400);

// Ablauf und Sperre
$portalDb->exec("UPDATE PortalToken SET expiresAt = '2020-01-01T00:00:00.000Z' WHERE revokedAt IS NULL AND clientId = '$clientId'");
expect('Abgelaufener Link → 401', call('GET', '/api/portal/me', null, null, $P), 401);
$res = call('GET', "/api/clients/$clientId/portal", null, $token);
check('Status zeigt abgelaufenen Zugang als inaktiv', $res[1]['active'] === false && $res[1]['expiresAt'] === '2020-01-01T00:00:00.000Z', $res[2]);
$P = ["X-Portal-Token: " . call('POST', "/api/clients/$clientId/portal", [], $token)[1]['token']];
$res = call('GET', "/api/clients/$clientId/portal", null, $token);
check('Neuer Zugang: aktiv, „zuletzt genutzt“ noch leer', $res[1]['active'] === true && $res[1]['lastUsedAt'] === null, $res[2]);
call('GET', '/api/portal/me', null, null, $P);
$res = call('GET', "/api/clients/$clientId/portal", null, $token);
check('Nutzung wird vermerkt („zuletzt genutzt“)', $res[1]['lastUsedAt'] !== null, $res[2]);
expect('Zugang sperren', call('DELETE', "/api/clients/$clientId/portal", null, $token), 204);
expect('Gesperrter Link → 401', call('GET', '/api/portal/me', null, null, $P), 401);
$res = call('GET', "/api/clients/$clientId/portal", null, $token);
check('Status nach Sperre: inaktiv', $res[1]['active'] === false, $res[2]);
$statuses = [];
for ($i = 0; $i < 21; $i++) {
    $statuses[] = call('GET', '/api/portal/me', null, null, ['X-Portal-Token: ' . bin2hex(random_bytes(16))])[0];
}
check('Durchprobieren von Schlüsseln wird nach 20 Fehlversuchen gebremst (429)', end($statuses) === 429 && in_array(401, $statuses, true), $statuses);
expect('Mitarbeiter-Zugang ist vom Portal-Limit nicht betroffen', call('GET', '/api/clients', null, $token), 200);
$res = call('GET', '/portal');
check('Portal-Seite wird ausgeliefert (mit Content-Security-Policy)', $res[0] === 200 && str_contains($res[2], '/assets/portal.js'), substr($res[2], 0, 80));
$res = call('GET', '/assets/portal.js');
check('Portal-Skript abrufbar', $res[0] === 200 && str_contains($res[2], 'use strict'));

echo "Benutzerverwaltung\n";
$res = call('POST', '/api/users', ['name' => 'Mitarbeiter', 'email' => 'team@example.com', 'password' => 'team12345'], $token);
check('Admin legt Mitarbeiter an', $res[0] === 201 && $res[1]['role'] === 'MEMBER' && !isset($res[1]['passwordHash']), $res[2]);
$memberId = $res[1]['id'];
$member = call('POST', '/api/auth/login', ['email' => 'team@example.com', 'password' => 'team12345'])[1]['token'];
expect('Mitarbeiter darf Kunden lesen', call('GET', '/api/clients', null, $member), 200);
expect('Mitarbeiter darf keine Benutzer anlegen → 403', call('POST', '/api/users', ['name' => 'Evil', 'email' => 'e@example.com', 'password' => 'evil12345'], $member), 403);
expect('Mitarbeiter darf keine Benutzer ändern → 403', call('PATCH', "/api/users/$memberId", ['role' => 'ADMIN'], $member), 403);
$me = call('GET', '/api/users', null, $token);
check('Benutzerliste', $me[0] === 200 && count($me[1]) === 2, $me[2]);
$adminId = call('GET', '/api/auth/me', null, $token)[1]['id'];
expect('Letzten Admin nicht herabstufen → 400', call('PATCH', "/api/users/$adminId", ['role' => 'MEMBER'], $token), 400);
expect('Eigenes Konto nicht löschbar → 400', call('DELETE', "/api/users/$adminId", null, $token), 400);
expect('Mitarbeiter löschen', call('DELETE', "/api/users/$memberId", null, $token), 204);
expect('Token gelöschter Benutzer wird abgelehnt → 401', call('GET', '/api/clients', null, $member), 401);

echo "Backup\n";
$res = call('GET', '/api/backups', null, $token);
check('Backups: das automatische Cron-Backup ist vorhanden, Einstellungen sichtbar', $res[0] === 200 && count($res[1]['items']) === 1 && $res[1]['settings']['auto'] === true && $res[1]['settings']['keep'] === 14 && $res[1]['settings']['encrypted'] === false && $res[1]['lastBackupAt'] !== null, $res[2]);
$member = call('POST', '/api/users', ['name' => 'Aushilfe', 'email' => 'aushilfe@example.com', 'password' => 'aushilfe123'], $token)[1];
$memberToken = call('POST', '/api/auth/login', ['email' => 'aushilfe@example.com', 'password' => 'aushilfe123'])[1]['token'];
expect('Backups: Mitarbeiter ohne Zugriff → 403', call('GET', '/api/backups', null, $memberToken), 403);
expect('Backup erstellen: Mitarbeiter → 403', call('POST', '/api/backups', [], $memberToken), 403);
expect('Backup-Download: Mitarbeiter → 403', call('GET', '/api/backups/crm-backup-20260101-000000.zip', null, $memberToken), 403);
expect('Backups ohne Login → 401', call('GET', '/api/backups'), 401);
call('DELETE', "/api/users/{$member['id']}", null, $token);

$boundary2 = 'bnd' . bin2hex(random_bytes(6));
$upload = "--$boundary2\r\nContent-Disposition: form-data; name=\"clientId\"\r\n\r\n$clientId\r\n--$boundary2\r\nContent-Disposition: form-data; name=\"file\"; filename=\"sicherung.txt\"\r\nContent-Type: text/plain\r\n\r\nSicherungstest\r\n--$boundary2--\r\n";
$res = call('POST', '/api/documents', $upload, $token, ["Content-Type: multipart/form-data; boundary=$boundary2"]);
$uploadedUrl = $res[1]['url'];
$uploadedName = basename($uploadedUrl);
$clientTotal = call('GET', '/api/clients?pageSize=1', null, $token)[1]['meta']['total'];
$invoiceTotal = call('GET', '/api/invoices?pageSize=1', null, $token)[1]['meta']['total'];

$res = call('POST', '/api/backups', [], $token);
expect('Backup per Knopfdruck erstellen', $res, 201);
$backup = $res[1];
check('Backup: Name, Größe, 1 Dokument, unverschlüsselt (kein Passwort gesetzt)', preg_match('/^crm-backup-\d{8}-\d{6}\.zip$/', $backup['name']) === 1 && $backup['size'] > 1000 && $backup['uploads'] === 1 && $backup['encrypted'] === false, $backup);
$zipPath = "$tmp/backups/{$backup['name']}";
$zip = new ZipArchive();
$zip->open($zipPath);
$entries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entries[] = $zip->getNameIndex($i);
}
sort($entries);
check('Archiv enthält Datenbank, Manifest und das Dokument', $entries === ['database.sqlite', 'manifest.json', "uploads/$uploadedName"], $entries);
$dbCopy = "$tmp/backup-check.db";
file_put_contents($dbCopy, $zip->getFromName('database.sqlite'));
$check = new PDO('sqlite:' . $dbCopy);
check('Backup-Datenbank ist intakt und enthält alle Daten (Kunden, Rechnungen, Mahnungen, Abos)', $check->query('PRAGMA integrity_check')->fetchColumn() === 'ok' && (int) $check->query('SELECT COUNT(*) FROM Client')->fetchColumn() === $clientTotal && (int) $check->query('SELECT COUNT(*) FROM Invoice')->fetchColumn() === $invoiceTotal && (int) $check->query('SELECT COUNT(*) FROM Reminder')->fetchColumn() >= 1 && (int) $check->query('SELECT COUNT(*) FROM Recurring')->fetchColumn() >= 1, [$clientTotal, $invoiceTotal]);
check('Dokument im Backup hat den richtigen Inhalt', $zip->getFromName("uploads/$uploadedName") === 'Sicherungstest');
$manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
check('Manifest: Prüfsumme passt zur Datenbank, Migrationen aufgelistet', $manifest['database']['sha256'] === hash('sha256', (string) $zip->getFromName('database.sqlite')) && $manifest['uploads'] === 1 && count($manifest['migrations']) === count(glob("$root/database/migrations/*.sql")), $manifest);
$zip->close();
unset($check);

$res = call('GET', '/api/backups', null, $token);
check('Backup-Liste zeigt beide Sicherungen, neueste zuerst', count($res[1]['items']) === 2 && $res[1]['items'][0]['name'] === $backup['name'], $res[2]);
$headers = get_headers($base . "/api/backups/{$backup['name']}", true, stream_context_create(['http' => ['header' => "Authorization: Bearer $token"]]));
$dl = call('GET', "/api/backups/{$backup['name']}", null, $token);
check('Backup herunterladen (ZIP, gleiche Größe wie auf der Platte)', $dl[0] === 200 && str_contains((string) ($headers['Content-Type'] ?? ''), 'application/zip') && strlen($dl[2]) === filesize($zipPath) && str_starts_with($dl[2], 'PK'), $headers);
expect('Download mit Pfad-Trick → 404', call('GET', '/api/backups/..%2F..%2Fdatabase%2Fapp.db', null, $token), 404);
expect('Download mit ungültigem Namen → 404', call('GET', '/api/backups/crm-backup-1.zip', null, $token), 404);
expect('Download unbekanntes Backup → 404', call('GET', '/api/backups/crm-backup-20200101-000000.zip', null, $token), 404);
expect('Löschen mit Pfad-Trick → 404', call('DELETE', '/api/backups/..%2F..%2F.env', null, $token), 404);
expect('Backup löschen', call('DELETE', "/api/backups/{$res[1]['items'][1]['name']}", null, $token), 204);

// Aufbewahrung (Rotation)
$rot = "$tmp/rotation";
mkdir($rot);
foreach (['20261003-020000', '20261002-020000', '20261001-020000', '20260930-020000', '20260929-020000', '20260915-020000', '20260828-020000', '20260805-020000', '20260730-020000'] as $stamp) {
    file_put_contents("$rot/crm-backup-$stamp.zip", 'x');
}
file_put_contents("$rot/notiz.txt", 'bleibt');
putenv("BACKUP_DIR=$rot"); putenv('BACKUP_KEEP=3'); putenv('BACKUP_KEEP_MONTHS=2');
$deleted = App\Services\BackupService::rotate();
$left = array_map('basename', glob("$rot/*"));
sort($left);
check('Rotation: 3 neueste + je 1 pro Monat für die letzten 2 Monate bleiben, Rest wird gelöscht', $deleted === 5 && $left === ['crm-backup-20260930-020000.zip', 'crm-backup-20261001-020000.zip', 'crm-backup-20261002-020000.zip', 'crm-backup-20261003-020000.zip', 'notiz.txt'], [$deleted, $left]);
putenv('BACKUP_DIR=' . "$tmp/backups"); putenv('BACKUP_KEEP'); putenv('BACKUP_KEEP_MONTHS');

// Verschlüsselung und Wiederherstellung (Kommandozeile)
$cli = static function (string $script, array $args, array $extra) use ($root, $env): array {
    $p = proc_open(array_merge([PHP_BINARY, "$root/bin/$script"], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge($env, $extra));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
$secret = 'geheimes-backup-passwort-42';
$encDir = "$tmp/enc-backups";
[$code, $out] = $cli('backup.php', [], ['BACKUP_PASSPHRASE' => $secret, 'BACKUP_DIR' => $encDir, 'BACKUP_COPY_DIR' => "$tmp/copy"]);
check('bin/backup.php erstellt ein verschlüsseltes Backup und kopiert es ins Zweitverzeichnis', $code === 0 && str_contains($out, 'verschlüsselt') && str_contains($out, 'Kopie') && count(glob("$encDir/crm-backup-*.zip")) === 1 && count(glob("$tmp/copy/crm-backup-*.zip")) === 1, $out);
$encFile = glob("$encDir/crm-backup-*.zip")[0];
$z = new ZipArchive();
$z->open($encFile);
$st = $z->statName('database.sqlite');
$readable = $z->getFromName('database.sqlite');
check('Verschlüsselung: AES-256, ohne Passwort ist nichts lesbar', $st['encryption_method'] === ZipArchive::EM_AES_256 && ($readable === false || !str_contains((string) $readable, 'SQLite format 3')) && !str_contains((string) file_get_contents($encFile), 'SQLite format 3') && !str_contains((string) file_get_contents($encFile), 'Beispiel GmbH'));
$z->setPassword($secret);
check('Mit Passwort ist die Datenbank lesbar', str_starts_with((string) $z->getFromName('database.sqlite'), 'SQLite format 3'));
$z->close();

$restoredDb = "$tmp/restored/app.db";
$restoreEnv = ['DATABASE_PATH' => $restoredDb, 'UPLOAD_DIR' => "$tmp/restored-uploads", 'BACKUP_DIR' => "$tmp/restore-safety", 'BACKUP_PASSPHRASE' => $secret];
[$code, $out] = $cli('restore.php', [$encFile], $restoreEnv);
check('Wiederherstellung verlangt ausdrückliche Bestätigung (--yes), tut ohne nichts', $code === 1 && str_contains($out, '--yes') && !is_file($restoredDb), $out);
[$code, $out] = $cli('restore.php', [$encFile, '--yes'], ['BACKUP_PASSPHRASE' => 'falsches-passwort'] + $restoreEnv);
check('Falsches Passwort: Abbruch mit klarer Meldung, nichts wird angelegt', $code === 1 && str_contains($out, 'Passwort') && !is_file($restoredDb), $out);
[$code, $out] = $cli('restore.php', [$encFile, '--yes'], $restoreEnv);
check('Wiederherstellung in neue Datenbank erfolgreich', $code === 0 && str_contains($out, 'wiederhergestellt') && is_file($restoredDb), $out);
$restored = new PDO('sqlite:' . $restoredDb);
check('Wiederhergestellte Datenbank: gleiche Kunden und Rechnungen, intakt', $restored->query('PRAGMA integrity_check')->fetchColumn() === 'ok' && (int) $restored->query('SELECT COUNT(*) FROM Client')->fetchColumn() === $clientTotal && (int) $restored->query('SELECT COUNT(*) FROM Invoice')->fetchColumn() === $invoiceTotal && (int) $restored->query('SELECT COUNT(*) FROM User')->fetchColumn() >= 1);
check('Wiederhergestelltes Dokument hat den richtigen Inhalt', file_get_contents("$tmp/restored-uploads/$uploadedName") === 'Sicherungstest');
unset($restored);
[$code, $out] = $cli('restore.php', [$encFile, '--yes'], $restoreEnv);
check('Wiederherstellung über bestehende Datenbank sichert den alten Stand vorher', $code === 0 && count(glob("$tmp/restore-safety/vor-wiederherstellung-*.sqlite")) === 1 && str_contains($out, 'gesichert'), $out);

// Zip-Slip: präparierte Archive dürfen nichts außerhalb des Upload-Ordners schreiben
$evil = "$tmp/evil.zip";
$ez = new ZipArchive();
$ez->open($evil, ZipArchive::CREATE);
$ez->addFromString('database.sqlite', (string) file_get_contents($dbCopy));
$ez->addFromString('uploads/../../evil-escape.txt', 'böse');
$ez->addFromString('../evil-escape2.txt', 'böse');
$ez->addFromString('uploads/.versteckt', 'böse');
$ez->addFromString('uploads/gut.txt', 'ok');
$ez->close();
[$code, $out] = $cli('restore.php', [$evil, '--yes'], ['BACKUP_PASSPHRASE' => ''] + $restoreEnv);
check('Zip-Slip: Archiv mit „..“-Pfaden wird nur für harmlose Dateien entpackt', $code === 0 && !file_exists("$tmp/evil-escape.txt") && !file_exists("$tmp/evil-escape2.txt") && !file_exists("$tmp/restored-uploads/.versteckt") && file_get_contents("$tmp/restored-uploads/gut.txt") === 'ok' && str_contains($out, '1 Dokument'), $out);
file_put_contents("$tmp/kaputt.zip", 'das ist kein zip');
[$code, $out] = $cli('restore.php', ["$tmp/kaputt.zip", '--yes'], $restoreEnv);
check('Beschädigte Datei: sauberer Abbruch', $code === 1 && str_contains($out, 'nicht geöffnet'), $out);
$tampered = "$tmp/tampered.zip";
$tz = new ZipArchive();
$tz->open($tampered, ZipArchive::CREATE);
$tz->addFromString('database.sqlite', (string) file_get_contents($dbCopy));
$tz->addFromString('manifest.json', json_encode(['database' => ['sha256' => str_repeat('0', 64)]]));
$tz->close();
[$code, $out] = $cli('restore.php', [$tampered, '--yes'], ['BACKUP_PASSPHRASE' => ''] + $restoreEnv);
check('Falsche Prüfsumme im Manifest: Wiederherstellung wird verweigert', $code === 1 && str_contains($out, 'Prüfsumme'), $out);

// Skripte mehrfach ausführbar (Regression: „SELECT 1“-Abfragen)
$seedEnv = ['DATABASE_PATH' => "$tmp/seedtest.db", 'BACKUP_DIR' => "$tmp/seed-backups"];
[$code] = $cli('migrate.php', [], $seedEnv);
[$c1, $o1] = $cli('seed.php', [], $seedEnv);
[$c2, $o2] = $cli('seed.php', [], $seedEnv);
[$c3, $o3] = $cli('seed-catalog.php', [], $seedEnv);
[$c4, $o4] = $cli('seed-catalog.php', [], $seedEnv);
check('bin/seed.php und bin/seed-catalog.php sind beliebig oft ausführbar', $code === 0 && $c1 === 0 && $c2 === 0 && $c3 === 0 && $c4 === 0 && str_contains($o3, '3 Kategorie(n) und 9 Produkt(e)') && str_contains($o4, '0 Kategorie(n) und 0 Produkt(e)'), [$o2, $o4]);

// Cron-Anbindung
foreach (glob("$tmp/backups/crm-backup-*.zip") as $f) {
    unlink($f);
}
$res = call('POST', '/api/cron/run', null, null, ['X-Cron-Token: cron-token-cron-token-123']);
check('Cron legt ohne aktuelles Backup eines an, danach nicht mehr', is_array($res[1]['backup'] ?? null), $res[2]);
$res = call('POST', '/api/cron/run', null, null, ['X-Cron-Token: cron-token-cron-token-123']);
check('Cron: zweiter Lauf sichert nicht erneut', $res[1]['backup'] === null, $res[2]);
[$code, $out] = $cli('cron.php', [], []);
check('bin/cron.php meldet „Backup aktuell“', $code === 0 && str_contains($out, 'Backup aktuell'), $out);

echo "Sicherheit\n";
$forged = (static function () {
    $b = static fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $head = $b('{"alg":"none","typ":"JWT"}');
    $body = $b(json_encode(['id' => 'x', 'exp' => time() + 999]));
    return "$head.$body.";
})();
expect('JWT mit alg=none → 401', call('GET', '/api/clients', null, $forged), 401);
$parts = explode('.', $token);
expect('JWT mit manipulierter Signatur → 401', call('GET', '/api/clients', null, "{$parts[0]}.{$parts[1]}.AAAA"), 401);
expect('Ungültiges JSON → 400', call('POST', '/api/clients', '{kaputt', $token, ['Content-Type: application/json']), 400);
$res = call('OPTIONS', '/api/clients');
expect('CORS-Preflight', $res, 204);
$headers = get_headers($base . '/health', true);
check('Security-Header gesetzt', ($headers['X-Content-Type-Options'] ?? '') === 'nosniff' && ($headers['X-Frame-Options'] ?? '') === 'DENY', $headers);
for ($i = 0; $i < 5; $i++) {
    call('POST', '/api/auth/login', ['email' => 'ralph@example.com', 'password' => 'falsch-falsch']);
}
expect('Login nach 5 Fehlversuchen gesperrt → 429', call('POST', '/api/auth/login', ['email' => 'ralph@example.com', 'password' => 'geheim1234']), 429);

echo "\n$passed bestanden, $failed fehlgeschlagen\n";
if ($failed > 0 && is_file("$tmp/server.log")) {
    echo "--- Server-Log ---\n" . substr((string) file_get_contents("$tmp/server.log"), -2000) . "\n";
}
exit($failed > 0 ? 1 : 0);
