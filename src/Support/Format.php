<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Deutsche Darstellung von Beträgen, Mengen und Datumsangaben (für PDFs und E-Mails). */
final class Format
{
    public static function money(float $amount, string $currency = 'EUR'): string
    {
        return number_format($amount, 2, ',', '.') . ' ' . ($currency === 'EUR' ? '€' : $currency);
    }

    public static function qty(float $value): string
    {
        return (string) preg_replace('/,?0+$/', '', number_format($value, 2, ',', '.'));
    }

    public static function date(?string $iso): string
    {
        if (!$iso) {
            return '–';
        }
        return (new DateTimeImmutable($iso))->setTimezone(self::zone())->format('d.m.Y');
    }

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(Env::get('APP_TIMEZONE', 'Europe/Berlin') ?? 'Europe/Berlin');
    }
}
