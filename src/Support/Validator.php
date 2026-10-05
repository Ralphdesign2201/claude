<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\ApiError;

/**
 * Minimale Schema-Validierung (Ersatz für zod).
 *
 * Regeln pro Feld: type (string|number|int|bool|list|datetime), required, min, max, positive,
 * enum, email, emptyOk ('' wird zu null), default, items (Schema für Listen), minItems.
 * Unbekannte Felder werden verworfen. null zählt als ungültig (wie bei zod).
 */
final class Validator
{
    /**
     * @param array<string,array<string,mixed>> $schema
     * @return array<string,mixed>
     */
    public static function validate(mixed $input, array $schema, bool $partial = false): array
    {
        [$out, $errors] = self::run($input, $schema, $partial);
        if ($errors !== []) {
            throw ApiError::badRequest('Validierungsfehler', [
                'formErrors' => $errors['_form'] ?? [],
                'fieldErrors' => (object) array_diff_key($errors, ['_form' => 1]),
            ]);
        }
        return $out;
    }

    /** @return array{0:array<string,mixed>,1:array<string,list<string>>} */
    private static function run(mixed $input, array $schema, bool $partial): array
    {
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            return [[], ['_form' => ['Erwartet wird ein JSON-Objekt']]];
        }

        $out = [];
        $errors = [];
        foreach ($schema as $field => $rule) {
            if (!array_key_exists($field, $input)) {
                if (array_key_exists('default', $rule)) {
                    $out[$field] = $rule['default'];
                } elseif (!$partial && ($rule['required'] ?? false)) {
                    $errors[$field][] = 'Pflichtfeld';
                }
                continue;
            }
            [$value, $fieldErrors] = self::check($input[$field], $rule);
            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
            } else {
                $out[$field] = $value;
            }
        }
        return [$out, $errors];
    }

    /** @return array{0:mixed,1:list<string>} */
    private static function check(mixed $value, array $rule): array
    {
        $type = $rule['type'] ?? 'string';

        if (($rule['emptyOk'] ?? false) && $value === '') {
            return [null, []];
        }

        switch ($type) {
            case 'string':
                if (!is_string($value)) {
                    return [null, ['Text erwartet']];
                }
                $len = mb_strlen($value);
                if (isset($rule['min']) && $len < $rule['min']) {
                    return [null, $rule['min'] === 1 ? ['Darf nicht leer sein'] : ["Mindestens {$rule['min']} Zeichen"]];
                }
                if (isset($rule['max']) && $len > $rule['max']) {
                    return [null, ["Höchstens {$rule['max']} Zeichen"]];
                }
                if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) {
                    return [null, ['Erlaubt: ' . implode(', ', $rule['enum'])]];
                }
                if (($rule['email'] ?? false) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return [null, ['Ungültige E-Mail-Adresse']];
                }
                return [$value, []];

            case 'number':
            case 'int':
                $isNumber = is_int($value) || is_float($value);
                if (!$isNumber || ($type === 'int' && (float) (int) $value !== (float) $value)) {
                    return [null, [$type === 'int' ? 'Ganze Zahl erwartet' : 'Zahl erwartet']];
                }
                if (($rule['positive'] ?? false) && $value <= 0) {
                    return [null, ['Muss größer als 0 sein']];
                }
                return [$type === 'int' ? (int) $value : $value, []];

            case 'bool':
                return is_bool($value) ? [$value, []] : [null, ['true oder false erwartet']];

            case 'datetime':
                $date = is_string($value) ? Dates::normalize($value) : null;
                return $date !== null ? [$date, []] : [null, ['Ungültiges Datum (ISO 8601 erwartet)']];

            case 'list':
                if (!is_array($value) || !array_is_list($value)) {
                    return [null, ['Liste erwartet']];
                }
                if (isset($rule['minItems']) && count($value) < $rule['minItems']) {
                    return [null, ["Mindestens {$rule['minItems']} Eintrag/Einträge erforderlich"]];
                }
                $items = [];
                $errors = [];
                foreach ($value as $i => $item) {
                    [$clean, $itemErrors] = self::run($item, $rule['items'], false);
                    foreach ($itemErrors as $field => $messages) {
                        foreach ($messages as $message) {
                            $errors[] = "[$i]" . ($field === '_form' ? '' : ".$field") . ": $message";
                        }
                    }
                    $items[] = $clean;
                }
                return [$items, $errors];
        }

        return [null, ['Unbekannter Typ']];
    }
}
