<?php

declare(strict_types=1);

namespace App\Controllers;

/** SQLite speichert Booleans als 0/1 – für die JSON-Ausgabe zurück in true/false wandeln. */
final class Casts
{
    /**
     * @param array<string,mixed>|null $row
     * @param list<string> $boolColumns
     * @return array<string,mixed>|null
     */
    public static function row(?array $row, array $boolColumns): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach ($boolColumns as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = (bool) $row[$column];
            }
        }
        return $row;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $boolColumns
     * @return list<array<string,mixed>>
     */
    public static function rows(array $rows, array $boolColumns): array
    {
        return array_map(static fn ($row) => self::row($row, $boolColumns), $rows);
    }
}
