<?php

namespace Grav\Plugin\Kneipe;

use Grav\Common\Grav;
use Twig\Environment;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Twig-Funktionen für das Theme:
 *
 *   icon('calendar', 'klasse')  – Inline-SVG aus theme://icons (dekorativ)
 *   ktext(text)                 – Text maskiert, Zeilenumbrüche, Platzhalter markiert
 *   richtext(markdown)          – Markdown aus Zusatzfeldern, bereinigt
 *   safe_html(html)             – bereits gerendertes HTML (Seiteninhalt), bereinigt
 *   json_ld(daten)              – sicheres JSON für <script type="application/ld+json">
 *   asset('theme://css/site.css') – URL mit Versionsparameter für langes Caching
 */
final class TwigHelpers
{
    private static array $icons = [];

    public static function register(Environment $twig, Grav $grav): void
    {
        $twig->addFunction(new TwigFunction('icon', fn (string $name, string $class = '') => self::icon($grav, $name, $class)));
        $twig->addFunction(new TwigFunction('ktext', fn (mixed $text, bool $breaks = true) => new Markup(Service::text($text, $breaks), 'UTF-8')));
        $twig->addFunction(new TwigFunction('richtext', fn (mixed $markdown) => new Markup(Service::richtext($markdown), 'UTF-8')));
        $twig->addFunction(new TwigFunction('safe_html', fn (mixed $html) => new Markup(Service::markPlaceholders(Richtext::sanitize(is_string($html) ? $html : (string)$html)), 'UTF-8')));
        $twig->addFunction(new TwigFunction('asset', fn (string $stream) => self::asset($grav, $stream)));
        $twig->addFunction(new TwigFunction('json_ld', fn (array $data) => new Markup(self::jsonLd($data), 'UTF-8')));
    }

    public static function icon(Grav $grav, string $name, string $class = ''): Markup
    {
        $name = preg_replace('/[^a-z0-9-]/', '', $name) ?: 'info';

        if (array_key_exists($name, self::$icons) === false) {
            $file = $grav['locator']->findResource('theme://icons/' . $name . '.svg');
            $svg  = is_string($file) && is_file($file) ? trim((string)file_get_contents($file)) : '';
            // Inhalt zwischen <svg …> und </svg>
            self::$icons[$name] = preg_replace('~^<svg[^>]*>|</svg>$~', '', $svg) ?? '';
        }

        $classAttr = 'icon' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') : '');

        return new Markup(
            '<svg class="' . $classAttr . '" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . self::$icons[$name] . '</svg>',
            'UTF-8'
        );
    }

    public static function asset(Grav $grav, string $stream): string
    {
        $file = $grav['locator']->findResource($stream, true);
        $url  = $grav['locator']->findResource($stream, false);

        if (!is_string($file) || !is_string($url)) {
            return '';
        }

        $base = rtrim((string)$grav['uri']->rootUrl(false), '/');

        return $base . '/' . ltrim($url, '/') . '?v=' . substr(md5((string)filemtime($file)), 0, 8);
    }

    public static function jsonLd(array $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
