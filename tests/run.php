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

$env = [
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

$server = proc_open(
    [PHP_BINARY, '-d', 'upload_max_filesize=25M', '-d', 'post_max_size=26M', '-S', "127.0.0.1:$port", '-t', 'public', 'public/index.php'],
    [1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']],
    $pipes,
    $root,
    $env,
);

register_shutdown_function(static function () use ($server, $tmp) {
    proc_terminate($server);
    foreach (glob("$tmp/*") ?: [] as $f) {
        @unlink($f);
    }
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
