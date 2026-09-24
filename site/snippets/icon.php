<?php
/**
 * Inline-SVG-Symbol aus assets/icons/<name>.svg (eigene, schlichte
 * Linienzeichnungen – keine externe Iconbibliothek). Immer dekorativ:
 * Die Bedeutung trägt der danebenstehende Text.
 *
 * @var string $name
 * @var string|null $class
 */
static $cache = [];

$name = preg_replace('/[^a-z0-9-]/', '', $name ?? 'info');
$file = $kirby->root('index') . '/assets/icons/' . $name . '.svg';

if (isset($cache[$name]) === false) {
	$svg = is_file($file) ? trim(file_get_contents($file)) : '';
	// Inhalt zwischen <svg …> und </svg>
	$cache[$name] = preg_replace('!^<svg[^>]*>|</svg>$!', '', $svg);
}
?>
<svg class="icon<?= isset($class) ? ' ' . esc($class) : '' ?>" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $cache[$name] ?></svg>
