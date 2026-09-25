<?php

namespace Grav\Plugin\Kneipe;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Bereinigt das aus Markdown erzeugte HTML redaktioneller Inhalte.
 *
 * Grav maskiert HTML im Markdown bereits (pages.markdown.escape_markup).
 * Diese Klasse ist die zweite Sicherung bei der Ausgabe – z. B. falls
 * Inhaltsdateien von Hand bearbeitet wurden: nur eine kleine Liste von
 * Elementen bleibt
 * erhalten, Attribute nur bei Links (href mit sicheren Schemata).
 * Überschriften werden um eine Ebene abgesenkt, damit jede Seite genau
 * eine H1 behält.
 */
final class Richtext
{
    private const ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'a', 'ul', 'ol', 'li',
        'h2', 'h3', 'h4', 'blockquote', 'code', 's', 'u', 'sup', 'sub',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'template', 'noscript', 'form', 'input', 'button', 'select', 'textarea',
    ];

    private const RENAME = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('root');

        if ($root === null) {
            return '';
        }

        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= self::render($child);
        }

        return trim($output);
    }

    private static function render(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($node instanceof DOMElement === false) {
            return '';
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true) === true) {
            return '';
        }

        $inner = '';

        foreach (iterator_to_array($node->childNodes) as $child) {
            $inner .= self::render($child);
        }

        $tag = self::RENAME[$tag] ?? $tag;

        if (in_array($tag, self::ALLOWED, true) === false) {
            // unbekanntes Element: Inhalt behalten, Hülle verwerfen
            return $inner;
        }

        if ($tag === 'br') {
            return '<br>';
        }

        $attributes = '';

        if ($tag === 'a') {
            $href = self::safeHref($node->getAttribute('href'));

            if ($href === null) {
                return $inner;
            }

            $attributes = ' href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';

            if (preg_match('~^https?://~i', $href) === 1) {
                $attributes .= ' rel="noopener"';
            }
        }

        return '<' . $tag . $attributes . '>' . $inner . '</' . $tag . '>';
    }

    public static function safeHref(string $href): string|null
    {
        $href = trim($href);

        if ($href === '') {
            return null;
        }

        // relative Adressen und Anker
        if (preg_match('~^(/(?!/)|#)~', $href) === 1) {
            return $href;
        }

        if (preg_match('~^(https?://|mailto:|tel:)~i', $href) === 1) {
            return $href;
        }

        return null;
    }
}
