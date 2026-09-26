<?php

namespace Grav\Plugin\Kneipe;

/**
 * Ermittelt geänderte Felder zwischen zwei Inhaltsständen für das
 * Änderungsprotokoll eines Termins.
 */
final class Changelog
{
    /** Felder, die sich bei jeder Speicherung ändern oder das Protokoll selbst sind */
    public const IGNORE = [
        'uuid', 'modifiedby', 'modifiedat', 'changelog', 'changenote',
        'createdby', 'createdat', 'conflicts', 'permissions',
    ];

    public const MAX_ENTRIES = 100;

    /**
     * @return string[] Namen geänderter Felder (ohne IGNORE)
     */
    public static function changedFields(array $old, array $new): array
    {
        $old  = array_change_key_case($old, CASE_LOWER);
        $new  = array_change_key_case($new, CASE_LOWER);
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        $diff = [];

        foreach ($keys as $key) {
            if (in_array($key, self::IGNORE, true) === true) {
                continue;
            }

            $a = self::normalize($old[$key] ?? '');
            $b = self::normalize($new[$key] ?? '');

            if ($a !== $b) {
                $diff[] = $key;
            }
        }

        sort($diff);

        return $diff;
    }

    /** Vergleichbarer Text für einfache Werte und Listen */
    private static function normalize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_scalar($value) || $value === null) {
            return trim((string)$value);
        }

        return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Neuer Protokolleintrag vorne, Liste auf MAX_ENTRIES gekürzt.
     */
    public static function prepend(array $entries, array $entry): array
    {
        array_unshift($entries, $entry);
        return array_slice($entries, 0, self::MAX_ENTRIES);
    }
}
