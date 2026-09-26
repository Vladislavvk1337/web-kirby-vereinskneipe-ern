<?php

namespace Grav\Plugin\Kneipe;

/**
 * Lesezugriff auf das Frontmatter einer Seite ($this->page).
 */
trait HeaderAccess
{
    /** Rohwert aus dem Frontmatter */
    public function get(string $key, mixed $default = null): mixed
    {
        $header = (array)$this->page->header();

        return $header[$key] ?? $default;
    }

    /** Einfacher Text (Arrays und Objekte ergeben einen leeren Text) */
    public function string(string $key): string
    {
        $value = $this->get($key);

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : self::truthy($value);
    }

    /** Liste von Einträgen (list-Feld), nur Arrays */
    public function rows(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
