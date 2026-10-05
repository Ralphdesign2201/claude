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
    'DATABASE_PATH' => "$tmp/test.db",
    'JWT_SECRET' => 'test-secret-test-secret-123456',
    'ALLOW_REGISTRATION' => 'false',
    'LOGIN_RATE_LIMIT_MAX' => '5',
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
    foreach (array_merge(glob("$tmp/mail/*") ?: [], glob("$tmp/*") ?: []) as $f) {
        @unlink($f);
    }
    @rmdir("$tmp/mail");
    @rmdir($tmp);
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
check('Cron: 3 versäumte Monate werden als Entwürfe nachgeholt', $res[0] === 200 && $res[1]['created'] === 3 && count(array_unique(array_column($res[1]['runs'], 'number'))) === 3 && !in_array(true, array_column($res[1]['runs'], 'sent'), true), $res[2]);
$res = call('GET', "/api/invoices?recurringId=$hostingId&pageSize=50", null, $token);
$descriptions = array_map(static fn ($i) => $i['items'][0]['description'], $res[1]['items']);
check('Rechnungen aus dem Abo: Entwurf, Platzhalter ersetzt, Betrag 23,68 € brutto', count($res[1]['items']) === 3 && !str_contains(implode('|', $descriptions), '{') && str_contains($descriptions[0] . $descriptions[1] . $descriptions[2], gmdate('Y') ) && $res[1]['items'][0]['status'] === 'DRAFT' && $res[1]['items'][0]['totals']['total'] == 23.68 && $res[1]['items'][0]['recurringId'] === $hostingId, $descriptions);
$res = call('GET', "/api/recurring/$hostingId", null, $token);
check('Abo: Lauf 3 gezählt, nächster Termin = 1. des Folgemonats, Rechnungen verknüpft', $res[1]['occurrence'] === 3 && $res[1]['nextRunDate'] === gmdate('Y-m-d', strtotime('first day of next month')) . 'T00:00:00.000Z' && count($res[1]['invoices']) === 3 && $res[1]['lastRunAt'] !== null, $res[2]);
$res = call('POST', '/api/cron/run', null, null, ['X-Cron-Token: cron-token-cron-token-123']);
check('Cron ist idempotent: zweiter Lauf erzeugt nichts', $res[0] === 200 && $res[1]['created'] === 0, $res[2]);

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
