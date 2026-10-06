<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class Dates
{
    public const FORMAT = 'Y-m-d\TH:i:s.v\Z';

    private static int $lastMs = 0;

    /** Aktuelle Zeit; innerhalb eines Prozesses streng aufsteigend (Millisekunden), damit Reihenfolgen stabil bleiben. */
    public static function now(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        if ($ms <= self::$lastMs) {
            $ms = self::$lastMs + 1;
        }
        self::$lastMs = $ms;

        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }

    /** Normalisiert ISO-8601 (mit Zeitzone) oder YYYY-MM-DD nach UTC; null bei ungültiger Eingabe. */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        $isoWithZone = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';
        if (!preg_match($isoWithZone, $value) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }
}
