<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/** Kopiert alle Daten zwischen zwei Datenbanken (SQLite ↔ MySQL/MariaDB) mit identischem Schema. */
final class DatabaseTransfer
{
    /** Zeilen pro INSERT; bei MySQL ist die Zahl der Platzhalter begrenzt. */
    private const MAX_PLACEHOLDERS = 30000;

    /** @return array<string,int> Zeilen je Tabelle */
    public static function counts(PDO $pdo): array
    {
        $out = [];
        foreach (Migrator::tables() as $table) {
            $out[$table] = (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        }
        return $out;
    }

    public static function hasData(PDO $pdo): bool
    {
        foreach (['User', 'Client'] as $table) {
            try {
                if ((int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn() > 0) {
                    return true;
                }
            } catch (\PDOException) {
                // Tabelle fehlt: keine Daten
            }
        }
        return false;
    }

    /** Gibt es im Ziel schon Tabellen dieser Anwendung (auch leere)? */
    public static function hasTables(PDO $pdo, string $driver): bool
    {
        $sql = $driver === 'mysql'
            ? "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('User', 'Client', '_migrations')"
            : "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('User', 'Client', '_migrations')";
        return (int) $pdo->query($sql)->fetchColumn() > 0;
    }

    /** Löscht alle Zeilen der Anwendungstabellen (die Tabellen selbst bleiben). */
    public static function wipe(PDO $pdo, string $driver): void
    {
        self::foreignKeys($pdo, $driver, false);
        try {
            foreach (array_reverse(array_merge(Migrator::tables(), ['RateLimit'])) as $table) {
                $pdo->exec('DELETE FROM "' . $table . '"');
            }
        } finally {
            self::foreignKeys($pdo, $driver, true);
        }
    }

    /**
     * Kopiert alle Tabellen von $src nach $dst. Das Ziel muss migriert und leer sein.
     * Prüft danach die Zeilenzahlen und wirft bei Abweichung (das Ziel ist dann unbrauchbar und wird nicht verwendet).
     *
     * @return array<string,int> Zeilen je Tabelle
     */
    public static function copy(PDO $src, PDO $dst, string $dstDriver): array
    {
        self::foreignKeys($dst, $dstDriver, false);
        $dst->exec($dstDriver === 'mysql' ? 'START TRANSACTION' : 'BEGIN IMMEDIATE');
        try {
            foreach (Migrator::tables() as $table) {
                self::copyTable($src, $dst, $table);
            }
            $dst->exec('COMMIT');
        } catch (\Throwable $e) {
            $dst->exec('ROLLBACK');
            throw $e;
        } finally {
            self::foreignKeys($dst, $dstDriver, true);
        }
        $a = self::counts($src);
        $b = self::counts($dst);
        if ($a !== $b) {
            $diff = array_keys(array_filter($a, static fn ($n, $t) => ($b[$t] ?? -1) !== $n, ARRAY_FILTER_USE_BOTH));
            throw new RuntimeException('Die Zeilenzahlen stimmen nach dem Kopieren nicht überein (' . implode(', ', $diff) . ').');
        }
        return $a;
    }

    private static function copyTable(PDO $src, PDO $dst, string $table): void
    {
        $rows = $src->query('SELECT * FROM "' . $table . '"');
        $buffer = [];
        $columns = null;
        $flush = static function () use (&$buffer, &$columns, $dst, $table): void {
            if ($buffer === [] || $columns === null) {
                return;
            }
            $one = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $stmt = $dst->prepare('INSERT INTO "' . $table . '" (' . implode(', ', array_map(static fn ($c) => '"' . $c . '"', $columns)) . ') VALUES ' . implode(', ', array_fill(0, count($buffer), $one)));
            $i = 1;
            foreach ($buffer as $row) {
                foreach ($row as $v) {
                    $stmt->bindValue($i++, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
                }
            }
            $stmt->execute();
            $buffer = [];
        };
        foreach ($rows as $row) {
            $columns ??= array_keys($row);
            $buffer[] = array_values($row);
            if (count($buffer) * count($columns) >= self::MAX_PLACEHOLDERS - count($columns)) {
                $flush();
            }
        }
        $flush();
    }

    private static function foreignKeys(PDO $pdo, string $driver, bool $on): void
    {
        $pdo->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = ' . ($on ? '1' : '0') : 'PRAGMA foreign_keys = ' . ($on ? 'ON' : 'OFF'));
    }

    /** Schreibt den Inhalt einer (MySQL-)Datenbank als frische SQLite-Datei – Grundlage für Backups. */
    public static function exportToSqlite(PDO $src, string $file): void
    {
        @unlink($file);
        $dst = Db::connect(['driver' => 'sqlite', 'path' => $file]);
        Migrator::run($dst, 'sqlite');
        // Gleicher Datenstand wie bei einem Schnappschuss, auch während die Anwendung weiterschreibt
        $mysql = $src->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $src->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }
        try {
            self::copy($src, $dst, 'sqlite');
        } finally {
            if ($mysql) {
                $src->exec('COMMIT');
            }
        }
        $dst->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $dst->exec('PRAGMA journal_mode = DELETE');
    }

    /** Lädt eine SQLite-Datei in die (migrierte) Zieldatenbank und ersetzt deren Inhalt. */
    public static function importFromSqlite(string $file, PDO $dst, string $dstDriver): void
    {
        $src = Db::connect(['driver' => 'sqlite', 'path' => $file]);
        Migrator::run($src, 'sqlite'); // ältere Backups auf den aktuellen Stand bringen
        Migrator::run($dst, $dstDriver);
        self::wipe($dst, $dstDriver);
        self::copy($src, $dst, $dstDriver);
    }
}
