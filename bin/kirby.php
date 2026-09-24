<?php

/**
 * Gemeinsamer Start für CLI-Skripte: lädt Kirby mit denselben Roots wie
 * die Website (Umgebungsvariablen bzw. .env).
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$base = dirname(__DIR__);

require $base . '/kirby/bootstrap.php';
require $base . '/site/bootstrap.php';

return new Kirby([
	'roots' => kneipe_roots($base),
]);
