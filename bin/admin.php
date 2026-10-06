<?php

declare(strict_types=1);

// Notfall-Werkzeug für ausgesperrte Administratoren (nur per Kommandozeile):
//   php bin/admin.php --list                 alle Benutzer anzeigen
//   php bin/admin.php --unlock               Anmeldesperre (zu viele Fehlversuche) aufheben
//   php bin/admin.php --password=BENUTZER    neues Passwort für einen Benutzer (Name oder E-Mail) setzen; wird abgefragt und nicht angezeigt

require __DIR__ . '/../src/bootstrap.php';

use App\Support\Db;

$opts = getopt('', ['list', 'unlock', 'password:']);
if (isset($opts['list'])) {
    foreach (Db::all('SELECT "name", "username", "email", "role" FROM "User" ORDER BY "createdAt"') as $u) {
        echo sprintf("%-8s  Benutzername: %-20s  E-Mail: %s  (%s)\n", $u['role'], $u['username'] ?? '–', $u['email'], $u['name']);
    }
} elseif (isset($opts['unlock'])) {
    $n = Db::run('DELETE FROM "RateLimit" WHERE "key" LIKE ?', ['login:%']);
    echo "Anmeldesperre aufgehoben ($n Eintrag/Einträge).\n";
} elseif (isset($opts['password'])) {
    $login = strtolower(trim((string) $opts['password']));
    $user = Db::one('SELECT "id", "email" FROM "User" WHERE "email" = ? OR LOWER("username") = ?', [$login, $login]);
    if ($user === null) {
        fwrite(STDERR, "Benutzer nicht gefunden. Mit --list siehst du alle.\n");
        exit(1);
    }
    echo 'Neues Passwort (mind. 8 Zeichen): ';
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty -echo');
    }
    $pw = rtrim((string) fgets(STDIN), "\r\n");
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty echo');
    }
    echo "\n";
    if (strlen($pw) < 8 || strlen($pw) > 72) {
        fwrite(STDERR, "Das Passwort muss 8 bis 72 Zeichen lang sein.\n");
        exit(1);
    }
    Db::update('User', $user['id'], ['passwordHash' => password_hash($pw, PASSWORD_BCRYPT)]);
    Db::run('DELETE FROM "RateLimit" WHERE "key" LIKE ?', ['login:%']);
    echo "Passwort für {$user['email']} gesetzt, Anmeldesperre aufgehoben.\n";
} else {
    echo "Aufruf: php bin/admin.php --list | --unlock | --password=BENUTZER\n";
}
