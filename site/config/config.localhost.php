<?php

/**
 * Nur lokale Entwicklung (http://localhost:8000). Kirby lädt diese Datei
 * zusätzlich zu config.php, wenn der Host „localhost“ heißt.
 */

return [
	'debug' => kneipe_env_bool('KIRBY_DEBUG', true),
	'panel' => [
		// Lokal darf der erste Administrator im Browser angelegt werden
		'install' => true,
	],
];
