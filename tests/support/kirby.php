<?php

/**
 * Kirby-Testumgebung: eigene Kopie von content/, leere Konten, Sitzungen,
 * Cache und Medien in tests/tmp/. Legt je ein Konto für Administration
 * und Moderation an.
 */

use Kirby\Cms\App;

$root = dirname(__DIR__, 2);
require_once $root . '/kirby/bootstrap.php';

putenv('KIRBY_MAIL_TRANSPORT=none');
putenv('KIRBY_CONTENT_SALT=test-salt');
putenv('KIRBY_COOKIE_KEY=test-cookie');
$_ENV['KIRBY_MAIL_TRANSPORT'] = 'none';
require_once $root . '/site/bootstrap.php';

/** Fester „Jetzt“-Zeitpunkt passend zu den Demo-Inhalten */
const TEST_NOW = 1790244000; // 2026-09-24 12:00 Europe/Berlin

function test_tmp_dir(): string
{
	$dir = dirname(__DIR__) . '/tmp/' . bin2hex(random_bytes(4));
	@mkdir($dir, 0777, true);
	register_shutdown_function(fn () => remove_dir($dir));
	return $dir;
}

function fresh_kirby(array $options = []): App
{
	$root = dirname(__DIR__, 2);
	$tmp  = test_tmp_dir();
	copy_dir($root . '/content', $tmp . '/content');

	Kneipe\Service::flush();
	Kirby\Cms\Blueprint::$loaded = [];
	Kirby\Cms\ModelPermissions::$cache = [];
	Kirby\Email\Email::$emails = [];

	$kirby = new App([
		'roots' => [
			'index'    => $root,
			'site'     => $root . '/site',
			'content'  => $tmp . '/content',
			'accounts' => $tmp . '/accounts',
			'sessions' => $tmp . '/sessions',
			'cache'    => $tmp . '/cache',
			'media'    => $tmp . '/media',
			'logs'     => $tmp . '/logs',
		],
		'options' => ['url' => 'https://kneipe.example.org', ...$options],
	]);

	$kirby->impersonate('kirby', function () use ($kirby) {
		$kirby->users()->create(['email' => 'admin@example.org', 'role' => 'admin', 'name' => 'Test Admin', 'password' => 'test-passwort-123']);
		$kirby->users()->create(['email' => 'moderation@example.org', 'role' => 'moderator', 'name' => 'Test Moderation', 'password' => 'test-passwort-123']);
	});

	return $kirby;
}

/** Aktion als bestimmte Person ausführen */
function as_user(App $kirby, string $email, Closure $fn): mixed
{
	Kneipe\Service::flush();
	$result = $kirby->impersonate($email, $fn);
	Kneipe\Service::flush();
	return $result;
}

function event(App $kirby, string $slug): EventPage
{
	Kneipe\Service::flush();
	$page = $kirby->site()->find('termine')->findPageOrDraft($slug);

	if ($page instanceof EventPage === false) {
		fail('Termin nicht gefunden: ' . $slug);
	}

	return $page;
}
