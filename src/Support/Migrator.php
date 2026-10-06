<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Wendet database/migrations/*.sql an – für SQLite unverändert, für MySQL/MariaDB nach automatischer Übersetzung.
 * So gibt es genau eine Schema-Quelle, und beide Datenbanken haben garantiert dieselben Tabellen und Spalten.
 */
final class Migrator
{
    private const TS_DEFAULT_SQLITE = "(strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))";
    private const TS_DEFAULT_MYSQL = "(CONCAT(DATE_FORMAT(UTC_TIMESTAMP(3), '%Y-%m-%dT%H:%i:%s.'), LPAD(FLOOR(MICROSECOND(UTC_TIMESTAMP(3)) / 1000), 3, '0'), 'Z'))";
    private const MYSQL_TABLE_OPTIONS = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /** @return list<string> */
    public static function files(): array
    {
        $files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Tabellen in der Reihenfolge ihrer Anlage (Elterntabellen zuerst), ohne flüchtige Zähler.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        $names = [];
        foreach (self::files() as $file) {
            $sql = (string) file_get_contents($file);
            preg_match_all('/CREATE TABLE "(\w+)"/', $sql, $m);
            array_push($names, ...$m[1]);
        }
        return array_values(array_diff($names, ['RateLimit']));
    }

    /**
     * Legt fehlende Migrationen an. Gibt die Namen der neu angewendeten zurück.
     *
     * @return list<string>
     */
    public static function run(PDO $pdo, string $driver, ?callable $log = null): array
    {
        $mysql = $driver === 'mysql';
        $pdo->exec($mysql
            ? 'CREATE TABLE IF NOT EXISTS "_migrations" ("name" VARCHAR(255) NOT NULL PRIMARY KEY, "appliedAt" VARCHAR(40) NOT NULL)' . self::MYSQL_TABLE_OPTIONS
            : 'CREATE TABLE IF NOT EXISTS "_migrations" ("name" TEXT PRIMARY KEY, "appliedAt" TEXT NOT NULL)');
        $applied = $pdo->query('SELECT "name" FROM "_migrations"')->fetchAll(PDO::FETCH_COLUMN);
        $keyColumns = $mysql ? self::keyColumns() : [];

        $done = [];
        foreach (self::files() as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            if ($log) {
                $log($name);
            }
            $sql = (string) file_get_contents($file);
            if ($mysql) {
                foreach (self::statements($sql) as $stmt) {
                    foreach (self::toMysql($stmt, $keyColumns) as $translated) {
                        $pdo->exec($translated); // DDL ist bei MySQL nicht rückgängig zu machen
                    }
                }
                self::record($pdo, $name);
            } else {
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    $pdo->exec($sql);
                    self::record($pdo, $name);
                    $pdo->exec('COMMIT');
                } catch (\Throwable $e) {
                    $pdo->exec('ROLLBACK');
                    throw $e;
                }
            }
            $done[] = $name;
        }
        return $done;
    }

    private static function record(PDO $pdo, string $name): void
    {
        $stmt = $pdo->prepare('INSERT INTO "_migrations" ("name", "appliedAt") VALUES (?, ?)');
        $stmt->execute([$name, Dates::now()]);
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", $sql), static fn ($l) => !str_starts_with(ltrim($l), '--'));
        $out = [];
        foreach (preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [] as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }

    /**
     * Spalten, die in Schlüsseln, Indizes oder Fremdschlüsseln vorkommen (brauchen bei MySQL eine feste Länge statt TEXT).
     *
     * @return array<string,array<string,true>> Tabelle → Spalte → true
     */
    public static function keyColumns(): array
    {
        $keys = [];
        foreach (self::files() as $file) {
            $sql = (string) file_get_contents($file);
            if (preg_match_all('/CREATE (?:UNIQUE )?INDEX "\w+" ON "(\w+)"\s*\(([^)]*)\)/', $sql, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    preg_match_all('/"(\w+)"/', $x[2], $cols);
                    foreach ($cols[1] as $c) {
                        $keys[$x[1]][$c] = true;
                    }
                }
            }
            // Tabellenweise: FOREIGN KEY ("a") … und Spalten mit PRIMARY KEY / REFERENCES
            foreach (self::statements($sql) as $stmt) {
                if (preg_match('/^CREATE TABLE "(\w+)"/', $stmt, $t)) {
                    foreach (explode("\n", $stmt) as $line) {
                        if (preg_match('/FOREIGN KEY \("(\w+)"\)/', $line, $f)) {
                            $keys[$t[1]][$f[1]] = true;
                        }
                        if (preg_match('/^\s*"(\w+)"\s.*(PRIMARY KEY|REFERENCES|UNIQUE)/', $line, $f)) {
                            $keys[$t[1]][$f[1]] = true;
                        }
                    }
                } elseif (preg_match('/^ALTER TABLE "(\w+)" ADD COLUMN "(\w+)".*REFERENCES/s', $stmt, $a)) {
                    $keys[$a[1]][$a[2]] = true;
                }
            }
        }
        return $keys;
    }

    /**
     * Übersetzt eine SQLite-Anweisung nach MySQL/MariaDB (eine Anweisung kann zu mehreren werden).
     *
     * @param array<string,array<string,true>> $keys
     * @return list<string>
     */
    public static function toMysql(string $stmt, array $keys): array
    {
        if (preg_match('/^CREATE TABLE "(\w+)"/', $stmt, $t)) {
            $lines = explode("\n", $stmt);
            foreach ($lines as $i => $line) {
                if (preg_match('/^(\s*)"(\w+)"\s+(TEXT|INTEGER|REAL)\b(.*)$/', $line, $m)) {
                    $lines[$i] = $m[1] . '"' . $m[2] . '" ' . self::mysqlType($m[3], $m[4], isset($keys[$t[1]][$m[2]])) . self::mysqlRest($m[4]);
                }
            }
            return [implode("\n", $lines) . self::MYSQL_TABLE_OPTIONS];
        }
        if (preg_match('/^ALTER TABLE "(\w+)" ADD COLUMN "(\w+)"\s+(TEXT|INTEGER|REAL)\b(.*)$/s', $stmt, $m)) {
            $rest = $m[4];
            $fk = null;
            if (preg_match('/\s*REFERENCES\s+"(\w+)"\s*\("(\w+)"\)((?:\s+ON (?:DELETE|UPDATE) (?:SET NULL|CASCADE|RESTRICT|NO ACTION))*)/', $rest, $r)) {
                $fk = 'ALTER TABLE "' . $m[1] . '" ADD FOREIGN KEY ("' . $m[2] . '") REFERENCES "' . $r[1] . '" ("' . $r[2] . '")' . $r[3];
                $rest = str_replace($r[0], '', $rest);
            }
            $out = ['ALTER TABLE "' . $m[1] . '" ADD COLUMN "' . $m[2] . '" ' . self::mysqlType($m[3], $rest, isset($keys[$m[1]][$m[2]])) . self::mysqlRest($rest)];
            if ($fk !== null) {
                $out[] = $fk;
            }
            return $out;
        }
        return [$stmt];
    }

    private static function mysqlType(string $type, string $rest, bool $isKey): string
    {
        return match ($type) {
            'INTEGER' => 'BIGINT',
            'REAL' => 'DOUBLE',
            default => ($isKey || str_contains($rest, 'DEFAULT') || str_contains($rest, 'PRIMARY KEY')) ? 'VARCHAR(255)' : 'MEDIUMTEXT',
        };
    }

    private static function mysqlRest(string $rest): string
    {
        return str_replace(self::TS_DEFAULT_SQLITE, self::TS_DEFAULT_MYSQL, $rest);
    }
}
