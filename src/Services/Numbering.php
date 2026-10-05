<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Db;

final class Numbering
{
    private const PREFIXES = ['Invoice' => 'RE', 'Quote' => 'AN', 'ProductOrder' => 'BE'];

    /** Nächste Nummer im Format PRÄFIX-JJJJ-0001; basiert auf der höchsten vorhandenen Nummer, nicht auf der Anzahl. */
    public static function next(string $table): string
    {
        $prefix = self::PREFIXES[$table] . '-' . gmdate('Y') . '-';
        $max = (int) Db::value(
            "SELECT MAX(CAST(SUBSTR(\"number\", ?) AS INTEGER)) FROM \"$table\" WHERE \"number\" LIKE ? ESCAPE '\\'",
            [strlen($prefix) + 1, addcslashes($prefix, '%_\\') . '%'],
        );
        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
