<?php
/**
 * Inline-SVG-Symbole (eigene, schlichte Linienzeichnungen – keine externe
 * Iconbibliothek). Immer dekorativ: Bedeutung trägt der danebenstehende Text.
 *
 * @var string $name
 * @var string|null $class
 */
$paths = [
	'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
	'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
	'pin'      => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
	'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.8a3.3 3.3 0 0 1 0 6.4M18 14.8c2 .7 3.2 2.4 3.5 5.2"/>',
	'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.7 2.7L16.5 9"/>',
	'free'     => '<circle cx="12" cy="12" r="9" stroke-dasharray="3 2.4"/><path d="M12 8v8M8 12h8"/>',
	'cancel'   => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
	'lock'     => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
	'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
	'back'     => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
	'download' => '<path d="M12 4v11M7 10.5l5 5 5-5M5 20h14"/>',
	'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/>',
	'phone'    => '<path d="M6.5 3.5h3l1.5 4-2 1.5a11 11 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16.5 16.5 0 0 1 4.5 5.5a2 2 0 0 1 2-2z"/>',
	'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
	'close'    => '<path d="m6 6 12 12M18 6 6 18"/>',
	'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>',
	'alert'    => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.2v.3"/>',
	'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
	'door'     => '<path d="M5 21V4a1 1 0 0 1 1-1h9l3 2v16"/><path d="M3 21h18M12 12.5v.5"/>',
	'ticket'   => '<path d="M3 8a2 2 0 0 0 0 4v4h18v-4a2 2 0 0 1 0-4V4H3z" transform="translate(0 2)"/><path d="M14 6v12" stroke-dasharray="2 2"/>',
	'chevron-left'  => '<path d="m14.5 6-6 6 6 6"/>',
	'chevron-right' => '<path d="m9.5 6 6 6-6 6"/>',
	'double-left'   => '<path d="m12 6-6 6 6 6M18 6l-6 6 6 6"/>',
	'double-right'  => '<path d="m6 6 6 6-6 6M12 6l6 6-6 6"/>',
];
$name = $name ?? 'info';
?>
<svg class="icon<?= isset($class) ? ' ' . esc($class) : '' ?>" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $paths[$name] ?? $paths['info'] ?></svg>
