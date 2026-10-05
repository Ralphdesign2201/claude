<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\ApiError;
use PDO;
use Throwable;

final class Db
{
    private const HAS_UPDATED_AT = ['User', 'Client', 'Project', 'Task', 'Invoice', 'Contract', 'Note', 'Quote', 'Recurring', 'Category', 'Product', 'ProductOrder'];

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

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $path = self::path();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        return self::$pdo = $pdo;
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(12));
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::bind($params));
        return array_map([self::class, 'nest'], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        return self::all($sql, $params)[0] ?? null;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::bind($params));
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::bind($params));
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
        $pdo->exec('BEGIN IMMEDIATE');
        self::$depth++;
        try {
            $result = $fn();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            self::$depth--;
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
