<?php

/**
 * Kirby-Konfiguration – gilt für Produktion und Staging.
 *
 * Alles, was sich je Server unterscheidet oder geheim ist, kommt aus
 * Umgebungsvariablen (siehe .env.example). Lokale Entwicklung:
 * config.localhost.php ergänzt diese Datei.
 */

use Kirby\Http\Cookie;

// Geheimer Schlüssel für signierte Cookies (Sitzung, Panel-Anmeldung)
if ($cookieKey = kneipe_env('KIRBY_COOKIE_KEY')) {
	Cookie::$key = $cookieKey;
}

// Mailversand abschalten (lokal, Tests): Mails werden nur im Speicher
// gesammelt und nicht verschickt
if (kneipe_env('KIRBY_MAIL_TRANSPORT') === 'none') {
	Kirby\Email\Email::$debug = true;
}

$mailTransport = match (kneipe_env('KIRBY_MAIL_TRANSPORT', 'smtp')) {
	'smtp' => [
		'type'     => 'smtp',
		'host'     => kneipe_env('KIRBY_SMTP_HOST'),
		'port'     => (int)kneipe_env('KIRBY_SMTP_PORT', '587'),
		'security' => kneipe_env('KIRBY_SMTP_SECURITY', 'tls'),
		'auth'     => kneipe_env('KIRBY_SMTP_USER') !== null,
		'username' => kneipe_env('KIRBY_SMTP_USER'),
		'password' => kneipe_env('KIRBY_SMTP_PASSWORD'),
	],
	default => ['type' => 'mail'],
};

return [
	// Nie in Produktion aktivieren – zeigt Fehlerdetails an
	'debug' => kneipe_env_bool('KIRBY_DEBUG', false),

	// Feste Basis-URL verhindert Host-Header-Manipulation (Links in Mails,
	// Canonical-URLs). Leer = automatisch erkennen (nur lokal sinnvoll).
	'url' => kneipe_env('KIRBY_URL'),

	// Geheimes Salz für Medien-URLs und Vorschau-Tokens
	'content' => [
		'salt' => kneipe_env('KIRBY_CONTENT_SALT'),
	],

	'locale'       => 'de_DE.utf-8',

	'panel' => [
		// Erster Administrator wird per bin/create-user.php angelegt,
		// nie über das öffentliche Panel
		'install'  => false,
		'language' => 'de',
		'slug'     => 'panel',
		'css'      => 'assets/css/panel.css',
		'favicon'  => 'assets/brand/favicon.svg',
		'menu'     => require __DIR__ . '/panel-menu.php',
	],

	'auth' => [
		'methods' => ['password'],
		// fehlgeschlagene Anmeldungen je IP/Konto, danach Sperre
		'trials'  => 5,
		'timeout' => 3600,
	],

	// Panel-Sitzung: 2 Stunden, „angemeldet bleiben“ höchstens 7 Tage
	'session' => [
		'durationNormal' => 7200,
		'durationLong'   => 604800,
		'timeout'        => 3600,
	],

	// REST-API nur für das Panel, keine Basic-Auth
	'api' => [
		'basicAuth' => false,
	],

	'markdown' => [
		'extra'  => false,
		'breaks' => true,
	],

	'smartypants' => [
		'doublequote.open'  => '„',
		'doublequote.close' => '“',
		'singlequote.open'  => '‚',
		'singlequote.close' => '‘',
	],

	'thumbs' => [
		'driver'  => 'gd',
		'quality' => 78,
		'format'  => 'webp',
		'srcsets' => [
			'default' => [
				'480w'  => ['width' => 480],
				'800w'  => ['width' => 800],
				'1200w' => ['width' => 1200],
				'1600w' => ['width' => 1600],
			],
			'square' => [
				'160w' => ['width' => 160, 'height' => 160, 'crop' => true],
				'320w' => ['width' => 320, 'height' => 320, 'crop' => true],
			],
		],
	],

	'email' => [
		'transport' => $mailTransport,
	],

	// Projektspezifische Einstellungen (siehe site/plugins/kneipe)
	'kneipe' => [
		'from'      => kneipe_env('KIRBY_MAIL_FROM'),
		'fromName'  => kneipe_env('KIRBY_MAIL_FROM_NAME'),
		'noindex'   => kneipe_env_bool('KIRBY_NOINDEX', false),
		'requests'  => [
			// höchstens so viele Anfragen je IP-Adresse und Stunde
			'rateLimit'  => (int)kneipe_env('KNEIPE_RATE_LIMIT', '5'),
			// Mindestzeit zwischen Seitenaufruf und Absenden (Sekunden)
			'minSeconds' => (int)kneipe_env('KNEIPE_MIN_SECONDS', '3'),
		],
	],
];
