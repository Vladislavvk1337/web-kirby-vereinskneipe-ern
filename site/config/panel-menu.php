<?php

/**
 * Panel-Menü: Redaktionsbereiche direkt erreichbar. Einträge ohne
 * Berechtigung (Benutzer, System) blendet Kirby für Moderatoren selbst aus.
 */

use Kirby\Cms\App;

$entry = fn (string $id, string $label, string $icon) => [
	'label'   => $label,
	'icon'    => $icon,
	'link'    => 'pages/' . $id,
	'current' => fn (string|null $current = null): bool =>
		$current === 'site' &&
		str_starts_with(App::instance()->path(), 'panel/pages/' . $id),
];

return fn (App $kirby) => [
	'site' => [
		'label' => 'Übersicht',
		'icon'  => 'dashboard',
		'current' => fn (string|null $current = null): bool =>
			$current === 'site' &&
			str_starts_with($kirby->path(), 'panel/pages/') === false,
	],
	'termine'     => $entry('termine', 'Termine', 'calendar'),
	'anfragen'    => $entry('anfragen', 'Anfragen', 'email'),
	'thekenteams' => $entry('thekenteams', 'Thekenteams', 'users'),
	'aktuelles'   => $entry('aktuelles', 'Aktuelles', 'text'),
	'-',
	'users',
	'system',
];
