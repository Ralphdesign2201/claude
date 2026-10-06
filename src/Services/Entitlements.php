<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Dates;

/**
 * Was eine Lizenz erlaubt: Paket und Funktionen, Support-Zeitraum, Update-Zeitraum.
 *
 * Die Prüfung läuft immer auf dem Lizenzserver; die Software bekommt das Ergebnis signiert und kann es nicht selbst ändern.
 */
final class Entitlements
{
    /** Funktionen, die sich pro Lizenz freischalten lassen */
    public const FEATURES = [
        'support' => 'Support-Tickets und Hilfe-Artikel',
        'shop' => 'Katalog und Bestellungen im Kundenportal',
        'recurring' => 'Abos (wiederkehrende Rechnungen)',
        'licenses' => 'Lizenzverkauf an eigene Kunden',
    ];

    /** Vorgefertigte Pakete; „features“ einer Lizenz überschreibt das Paket */
    public const PLANS = [
        'starter' => ['name' => 'Starter', 'features' => []],
        'pro' => ['name' => 'Pro', 'features' => ['recurring', 'shop', 'support']],
        'agency' => ['name' => 'Agency', 'features' => ['recurring', 'shop', 'support', 'licenses']],
    ];

    /** @return list<string> */
    public static function parse(?string $features): array
    {
        $list = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $features)), static fn ($f) => $f !== '')));
        return in_array('*', $list, true) ? array_keys(self::FEATURES) : array_values(array_intersect($list, array_keys(self::FEATURES)));
    }

    /** Normalisiert Eingaben (Liste oder Text) zu „a,b,c“ oder null (= Paket entscheidet). */
    public static function normalize(mixed $features): ?string
    {
        if ($features === null || $features === '') {
            return null;
        }
        $list = is_array($features) ? $features : explode(',', (string) $features);
        $clean = self::parse(implode(',', array_map('strval', $list)));
        return implode(',', $clean);
    }

    /** @param array<string,mixed> $license @return list<string> */
    public static function features(array $license): array
    {
        if ($license['features'] !== null && $license['features'] !== '') {
            return self::parse($license['features']);
        }
        return self::PLANS[$license['plan'] ?? '']['features'] ?? [];
    }

    /** @param array<string,mixed> $license */
    public static function supportActive(array $license): bool
    {
        return $license['supportUntil'] === null || $license['supportUntil'] > Dates::now();
    }

    /** @param array<string,mixed> $license */
    public static function updatesActive(array $license, ?string $releasedAt = null): bool
    {
        // Wie bei klassischen Software-Lizenzen: Updates, die VOR dem Ablauf erschienen sind, darf der Kunde behalten und installieren
        return $license['updatesUntil'] === null || $license['updatesUntil'] > ($releasedAt ?? Dates::now());
    }

    /**
     * Setzt Support-/Update-Zeitraum ab Freischaltung. Tage: null = unbegrenzt, 0 = nicht enthalten.
     * Bei Mietlizenzen folgen beide dem bezahlten Zeitraum.
     *
     * @param array<string,mixed> $license
     * @return array<string,?string>
     */
    public static function dates(array $license, string $activatedAt, ?string $paidThrough, bool $rental): array
    {
        $calc = static function (?int $days) use ($activatedAt, $paidThrough, $rental): ?string {
            if ($days === 0) {
                return $activatedAt; // nicht enthalten: sofort abgelaufen
            }
            if ($rental) {
                return $paidThrough; // läuft mit der Miete
            }
            return $days === null ? null : (new \DateTimeImmutable($activatedAt))->modify("+$days days")->format(Dates::FORMAT);
        };
        return [
            'supportUntil' => $calc(($license['supportDays'] ?? null) === null ? null : (int) $license['supportDays']),
            'updatesUntil' => $calc(($license['updateDays'] ?? null) === null ? null : (int) $license['updateDays']),
        ];
    }
}
