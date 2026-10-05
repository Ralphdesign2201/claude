<?php

declare(strict_types=1);

namespace App\Support;

/** Festes Zeitfenster pro Schlüssel, gespeichert in SQLite (funktioniert auch ohne APCu/Redis). */
final class RateLimit
{
    /** Zählt einen Treffer und gibt die Anzahl im aktuellen Fenster zurück. */
    public static function hit(string $key, int $windowSeconds): int
    {
        $now = time();
        return (int) Db::transaction(static function () use ($key, $windowSeconds, $now) {
            $row = Db::one('SELECT "windowStart", "hits" FROM "RateLimit" WHERE "key" = ?', [$key]);
            if ($row === null || $row['windowStart'] + $windowSeconds <= $now) {
                Db::run(
                    'INSERT INTO "RateLimit" ("key", "windowStart", "hits") VALUES (?, ?, 1)
                     ON CONFLICT("key") DO UPDATE SET "windowStart" = excluded."windowStart", "hits" = 1',
                    [$key, $now],
                );
                if (random_int(1, 100) === 1) {
                    Db::run('DELETE FROM "RateLimit" WHERE "windowStart" + ? <= ?', [$windowSeconds, $now]);
                }
                return 1;
            }
            Db::run('UPDATE "RateLimit" SET "hits" = "hits" + 1 WHERE "key" = ?', [$key]);
            return $row['hits'] + 1;
        });
    }

    /** Aktuelle Anzahl, ohne zu zählen. */
    public static function count(string $key, int $windowSeconds): int
    {
        $row = Db::one('SELECT "windowStart", "hits" FROM "RateLimit" WHERE "key" = ?', [$key]);
        return ($row !== null && $row['windowStart'] + $windowSeconds > time()) ? (int) $row['hits'] : 0;
    }

    public static function clear(string $key): void
    {
        Db::run('DELETE FROM "RateLimit" WHERE "key" = ?', [$key]);
    }
}
