<?php

/**
 * Rollenabhängige Blueprint-Teile.
 *
 * site.yml bindet die Reiter „Stammdaten“ und „Einstellungen“ über
 * tabs/site-masterdata und tabs/site-settings ein. Administratoren sehen
 * die Felder, Moderatoren nur einen kurzen Hinweis – Empfängeradressen,
 * Löschfristen und der Freigabemodus bleiben so aus dem Blick.
 */

use Kirby\Cms\App;
use Kirby\Data\Yaml;

$forRole = function (string $file, string $label, string $icon) {
	return function (App $kirby) use ($file, $label, $icon) {
		$user = $kirby->user();

		if ($user !== null && ($user->isAdmin() === true || $user->id() === 'kirby')) {
			return Yaml::read(__DIR__ . '/blueprints/' . $file);
		}

		return [
			'label'    => $label,
			'icon'     => $icon,
			'sections' => [
				'restricted' => [
					'type'     => 'info',
					'headline' => $label,
					'text'     => 'Dieser Bereich wird von der Administration gepflegt.',
				],
			],
		];
	};
};

return [
	'tabs/site-masterdata' => $forRole('site-masterdata.yml', 'Stammdaten', 'home'),
	'tabs/site-settings'   => $forRole('site-settings.yml', 'Einstellungen', 'settings'),
];
