<?php

namespace Grav\Plugin\Kneipe;

/**
 * Umgebungsvariablen lesen – aus getenv(), $_SERVER oder $_ENV, je nachdem,
 * wo der Webserver sie ablegt. Leere Werte gelten als nicht gesetzt.
 */
final class Env
{
    public static function get(string $name): ?string
    {
        foreach ([getenv($name), $_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $value = self::get($name);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'ja'], true);
    }

    public static function int(string $name, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = filter_var(self::get($name), FILTER_VALIDATE_INT);

        if ($value === false) {
            return $default;
        }

        return max($min, min($max, $value));
    }
}
