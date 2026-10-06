<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\ApiError;
use PDO;
use Throwable;

final class Db
{
    private const HAS_UPDATED_AT = ['User', 'Client', 'Project', 'Task', 'Invoice', 'Contract', 'Note', 'Quote', 'Recurring', 'Category', 'Product', 'ProductOrder', 'PortalAccount', 'Ticket', 'CannedResponse', 'FaqArticle', 'LegalDocument'];

    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function path(): string
    {
        $path = Env::get('DATABASE_PATH', 'database/app.db');
        if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            $path = APP_ROOT . '/' . $path;
        }
        return $path;
    }

    /** @return array{driver:string,host:string,port:int,name:string,user:string,password:string} */
    public static function config(): array
    {
        return [
            'driver' => strtolower(Env::get('DB_DRIVER', 'sqlite') ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite',
            'host' => Env::get('DB_HOST', '127.0.0.1') ?: '127.0.0.1',
            'port' => Env::int('DB_PORT', 3306),
            'name' => Env::get('DB_NAME', '') ?? '',
            'user' => Env::get('DB_USER', '') ?? '',
            'password' => Env::get('DB_PASSWORD', '') ?? '',
        ];
    }

    public static function driver(): string
    {
        return self::config()['driver'];
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    /** Verwirft die offene Verbindung (nach dem Wechsel der Datenbank). */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$depth = 0;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $cfg = self::config();
        if ($cfg['driver'] === 'sqlite') {
            $cfg['path'] = self::path();
        }
        return self::$pdo = self::connect($cfg);
    }

    /**
     * Öffnet eine Verbindung. SQLite: ['driver' => 'sqlite', 'path' => …]; MySQL/MariaDB: host, port, name, user, password.
     * MySQL läuft im Modus ANSI_QUOTES, damit dieselben SQL-Texte ("Spalte") wie bei SQLite funktionieren.
     *
     * @param array<string,mixed> $cfg
     */
    public static function connect(array $cfg): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (($cfg['driver'] ?? 'sqlite') === 'mysql') {
            if (!extension_loaded('pdo_mysql')) {
                throw new \RuntimeException('Die PHP-Erweiterung „pdo_mysql“ fehlt auf diesem Server.');
            }
            $options[PDO::ATTR_TIMEOUT] = 8;
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4, time_zone = '+00:00', "
                . "sql_mode = 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES,STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'";
            $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . (int) $cfg['port'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
            return new PDO($dsn, (string) $cfg['user'], (string) $cfg['password'], $options);
        }
        $path = (string) $cfg['path'];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        return $pdo;
    }

    /** Ist der Fehler eine verletzte Eindeutigkeit (UNIQUE bzw. „Duplicate entry“)? */
    public static function isUniqueViolation(Throwable $e): bool
    {
        $m = $e->getMessage();
        return str_contains($m, 'UNIQUE constraint failed') || ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062);
    }

    /** @return 'unique'|'foreign'|'invalid'|null */
    public static function violationKind(Throwable $e): ?string
    {
        $m = $e->getMessage();
        $code = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        if (self::isUniqueViolation($e)) {
            return 'unique';
        }
        if (str_contains($m, 'FOREIGN KEY constraint failed') || in_array($code, [1451, 1452], true)) {
            return 'foreign';
        }
        if (str_contains($m, 'CHECK constraint failed') || str_contains($m, 'NOT NULL constraint failed') || in_array($code, [1048, 1364, 3819, 4025], true)) {
            return 'invalid';
        }
        return null;
    }

    /** Übersetzt die wenigen SQLite-Eigenheiten der Abfragen für MySQL/MariaDB. */
    private static function adapt(string $sql): string
    {
        if (!self::isMysql()) {
            return $sql;
        }
        return str_replace([' COLLATE NOCASE', ' AS INTEGER)'], ['', ' AS SIGNED)'], $sql);
    }

    /** @param \PDOStatement $stmt */
    private static function execute(\PDOStatement $stmt, array $params): void
    {
        $params = self::bind($params);
        if (!self::isMysql()) {
            $stmt->execute($params);
            return;
        }
        // Native Prepared Statements von MySQL brauchen echte Ganzzahlen (z. B. bei LIMIT ?)
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $stmt->execute();
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(12));
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare(self::adapt($sql));
        self::execute($stmt, $params);
        return array_map([self::class, 'nest'], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        return self::all($sql, $params)[0] ?? null;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare(self::adapt($sql));
        self::execute($stmt, $params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare(self::adapt($sql));
        self::execute($stmt, $params);
        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    public static function find(string $table, string $id): ?array
    {
        return self::one('SELECT * FROM ' . self::q($table) . ' WHERE "id" = ?', [$id]);
    }

    /** @return array<string,mixed> */
    public static function require(string $table, string $id, string $message): array
    {
        return self::find($table, $id) ?? throw ApiError::notFound($message);
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): string
    {
        $data['id'] ??= self::newId();
        $cols = implode(', ', array_map([self::class, 'q'], array_keys($data)));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        self::run('INSERT INTO ' . self::q($table) . " ($cols) VALUES ($marks)", array_values($data));
        return $data['id'];
    }

    /** @param array<string,mixed> $data */
    public static function update(string $table, string $id, array $data, string $notFound = 'Datensatz nicht gefunden'): void
    {
        if (in_array($table, self::HAS_UPDATED_AT, true)) {
            $data['updatedAt'] = Dates::now();
        }
        if ($data === []) {
            self::require($table, $id, $notFound);
            return;
        }
        $set = implode(', ', array_map(static fn ($c) => self::q($c) . ' = ?', array_keys($data)));
        $count = self::run('UPDATE ' . self::q($table) . " SET $set WHERE \"id\" = ?", [...array_values($data), $id]);
        if ($count === 0) {
            throw ApiError::notFound($notFound);
        }
    }

    public static function delete(string $table, string $id, string $notFound = 'Datensatz nicht gefunden'): void
    {
        if (self::run('DELETE FROM ' . self::q($table) . ' WHERE "id" = ?', [$id]) === 0) {
            throw ApiError::notFound($notFound);
        }
    }

    /** Führt $fn in einer Schreib-Transaktion aus (verschachtelte Aufrufe nutzen die äußere). */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$depth > 0) {
            return $fn();
        }
        $mysql = self::isMysql();
        $lock = null;
        if ($mysql) {
            // Wie bei SQLite soll immer nur ein Schreibvorgang gleichzeitig laufen (Nummernvergabe, Zähler)
            $lock = 'crm-write-' . substr(sha1(self::config()['name']), 0, 12);
            $got = $pdo->prepare('SELECT GET_LOCK(?, 15)');
            $got->execute([$lock]);
            if ((int) $got->fetchColumn() !== 1) {
                throw new \RuntimeException('Die Datenbank ist gerade belegt – bitte erneut versuchen.');
            }
            $pdo->exec('START TRANSACTION');
        } else {
            $pdo->exec('BEGIN IMMEDIATE');
        }
        self::$depth++;
        try {
            $result = $fn();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Verbindung weg: nichts mehr zu tun
            }
            throw $e;
        } finally {
            self::$depth--;
            if ($lock !== null) {
                try {
                    $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $rel->execute([$lock]);
                } catch (Throwable) {
                    // Sperre verfällt mit der Verbindung
                }
            }
        }
    }

    /** LIKE-Muster "enthält" mit maskierten Sonderzeichen (verwenden mit ESCAPE '\'). */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }

    /** @param list<string> $ids */
    public static function in(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    /**
     * Wandelt "gruppe__feld"-Spalten in verschachtelte Arrays um.
     * Sind alle Werte einer Gruppe NULL (LEFT JOIN ohne Treffer), wird die Gruppe NULL.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function nest(array $row): array
    {
        $out = [];
        $groups = [];
        foreach ($row as $key => $value) {
            if (is_string($key) && str_contains($key, '__')) {
                [$group, $field] = explode('__', $key, 2);
                $groups[$group][$field] = $value;
            } else {
                $out[$key] = $value;
            }
        }
        foreach ($groups as $group => $fields) {
            $isCount = $group === '_count';
            $out[$group] = (!$isCount && count(array_filter($fields, static fn ($v) => $v !== null)) === 0) ? null : $fields;
        }
        return $out;
    }

    private static function q(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Ungültiger Bezeichner: $identifier");
        }
        return '"' . $identifier . '"';
    }

    /** @return list<mixed> */
    private static function bind(array $params): array
    {
        return array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, array_values($params));
    }
}
