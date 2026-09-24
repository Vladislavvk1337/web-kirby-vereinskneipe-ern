<?php

/**
 * Minimales Test-Werkzeug ohne externe Abhängigkeiten.
 */

final class AssertionFailed extends Exception
{
}

final class TestRegistry
{
	public static array $tests = [];
	public static int $assertions = 0;
}

function test(string $name, callable $fn): void
{
	TestRegistry::$tests[] = [$name, $fn];
}

function fail(string $message): never
{
	throw new AssertionFailed($message);
}

function assert_true(mixed $condition, string $message = 'Bedingung nicht erfüllt'): void
{
	TestRegistry::$assertions++;

	if ($condition !== true) {
		fail($message);
	}
}

function assert_false(mixed $condition, string $message = 'Bedingung sollte falsch sein'): void
{
	assert_true($condition === false, $message);
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
	TestRegistry::$assertions++;

	if ($expected !== $actual) {
		fail(trim($message . ' – erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true), ' –'));
	}
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
	TestRegistry::$assertions++;

	if (str_contains($haystack, $needle) === false) {
		fail(trim($message . ' – „' . $needle . '“ fehlt', ' –'));
	}
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
	TestRegistry::$assertions++;

	if (stripos($haystack, $needle) !== false) {
		fail(trim($message . ' – „' . $needle . '“ darf nicht vorkommen', ' –'));
	}
}

function assert_throws(string $class, callable $fn, string $message = ''): Throwable
{
	TestRegistry::$assertions++;

	try {
		$fn();
	} catch (Throwable $e) {
		if ($e instanceof $class) {
			return $e;
		}

		fail(trim($message . ' – erwartet ' . $class . ', erhalten ' . $e::class . ': ' . $e->getMessage(), ' –'));
	}

	fail(trim($message . ' – ' . $class . ' wurde nicht geworfen', ' –'));
}

/** Verzeichnis rekursiv kopieren bzw. löschen */
function copy_dir(string $from, string $to): void
{
	@mkdir($to, 0777, true);

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
		$target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
		$item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
	}
}

function remove_dir(string $dir): void
{
	if (is_dir($dir) === false) {
		return;
	}

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}

	rmdir($dir);
}

// Reine Klassen des Plugins ohne Kirby laden
spl_autoload_register(function (string $class): void {
	if (str_starts_with($class, 'Kneipe\\')) {
		$file = dirname(__DIR__, 2) . '/site/plugins/kneipe/src/' . substr($class, 7) . '.php';

		if (is_file($file)) {
			require $file;
		}
	}
});

date_default_timezone_set('Europe/Berlin');
