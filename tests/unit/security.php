<?php

/**
 * Statische Prüfungen der Sicherheits- und Datenschutzkonfiguration.
 */

$root = dirname(__DIR__, 2);

test('Webserver sperren content/, site/, kirby/ und versteckte Dateien', function () use ($root) {
	$htaccess = file_get_contents($root . '/.htaccess');
	foreach (['^content/', '^site/', '^kirby/', '\.(?!well-known'] as $rule) {
		assert_contains($rule, $htaccess, '.htaccess');
	}

	$caddy = file_get_contents($root . '/deploy/caddy/Caddyfile');
	foreach (['/content/*', '/site/*', '/kirby/*', '/vendor/*', 'path */.*', 'ip_mask 24 48', 'Content-Security-Policy', "script-src 'self';"] as $needle) {
		assert_contains($needle, $caddy, 'Caddyfile');
	}
});

test('keine Geheimnisse und Laufzeitdaten im Repository', function () use ($root) {
	$ignore = file_get_contents($root . '/.gitignore');
	foreach (['.env', '/site/accounts/*', '/site/sessions/', '/content/anfragen/_drafts/', '/kirby/', '/vendor/'] as $entry) {
		assert_contains($entry, $ignore, '.gitignore');
	}

	$example = file_get_contents($root . '/.env.example');
	assert_contains('KIRBY_CONTENT_SALT=' . "\n", $example, 'Salt ohne Wert');
	assert_contains('KIRBY_SMTP_PASSWORD=' . "\n", $example, 'Passwort ohne Wert');
});

test('Produktion: Debug aus, Panel-Installation aus, sichere Voreinstellungen', function () use ($root) {
	$config = file_get_contents($root . '/site/config/config.php');
	assert_contains("'debug' => kneipe_env_bool('KIRBY_DEBUG', false)", $config);
	assert_contains("'install'  => false", $config);
	assert_contains("'basicAuth' => false", $config);
	assert_contains("'salt' => kneipe_env('KIRBY_CONTENT_SALT')", $config);
});

test('Uploads sind auf Bildtypen und Größen beschränkt', function () use ($root) {
	$image = file_get_contents($root . '/site/blueprints/files/image.yml');
	assert_contains('maxsize: 10000000', $image);
	assert_not_contains('svg', $image, 'SVG nur als Logo');
	$logo = file_get_contents($root . '/site/blueprints/files/logo.yml');
	assert_contains('image/svg+xml', $logo);
	assert_contains('maxsize: 2000000', $logo);
});

test('keine externen Schriften, Skripte oder Tracker in Templates und CSS', function () use ($root) {
	$files = [
		...glob($root . '/site/snippets/*.php'), ...glob($root . '/site/snippets/*/*.php'),
		...glob($root . '/site/templates/*.php'), ...glob($root . '/assets/css/*.css'), ...glob($root . '/assets/js/*.js'),
	];

	foreach ($files as $file) {
		$code = file_get_contents($file);
		foreach (['fonts.googleapis', 'fonts.gstatic', 'googletagmanager', 'google-analytics', 'cdn.', 'unpkg', 'maps.google', '<iframe'] as $bad) {
			assert_not_contains($bad, $code, basename($file));
		}
	}
});

test('Container: nicht als root, Daten im Volume, Sperren und CSP wie auf dem Server', function () use ($root) {
	$dockerfile = file_get_contents($root . '/Dockerfile');
	assert_contains('USER www-data', $dockerfile);
	assert_contains('VOLUME ["/data"]', $dockerfile);
	assert_contains('KIRBY_CONTENT_ROOT=/data/content', $dockerfile);
	assert_contains('KIRBY_DEBUG=false', $dockerfile);
	$code = implode("\n", array_filter(explode("\n", $dockerfile), fn ($line) => str_starts_with(ltrim($line), '#') === false));
	foreach (['KIRBY_CONTENT_SALT=', 'KIRBY_COOKIE_KEY=', 'KIRBY_SMTP_PASSWORD=', 'KNEIPE_ADMIN_PASSWORD='] as $secret) {
		assert_not_contains($secret, $code, 'keine Geheimnisse im Image');
	}

	$apache = file_get_contents($root . '/docker/apache-kneipe.conf');
	foreach (['(content|site|kirby|vendor', '(^|/)\\.(?!well-known/)', 'AllowOverride None', 'RemoteIPHeader X-Forwarded-For', "script-src 'self';", 'disable_functions'] as $needle) {
		assert_contains($needle, $apache, 'apache-kneipe.conf');
	}

	$entrypoint = file_get_contents($root . '/docker/docker-entrypoint.sh');
	assert_contains('KIRBY_CONTENT_SALT KIRBY_COOKIE_KEY', $entrypoint, 'Start ohne Geheimnisse verhindern');

	$ignore = file_get_contents($root . '/.dockerignore');
	foreach (['.env', 'site/accounts/', 'content/anfragen/_drafts/', '.git'] as $entry) {
		assert_contains($entry, $ignore, '.dockerignore');
	}
});
