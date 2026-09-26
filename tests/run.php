<?php

/**
 * Testlauf: php tests/run.php [--unit] [--http] [--filter=text]
 *
 * Ohne Auswahl laufen alle Gruppen. Unit-Tests brauchen nur PHP (einige
 * zusätzlich symfony/yaml aus dem Grav-Kern). HTTP-Tests bauen eine eigene
 * Grav-Testinstanz unter tests/tmp/ (Grav-Paket aus .grav/dist bzw.
 * Download) mit den Beispielinhalten aus seed/ und prüfen Website,
 * Formular und Admin-API (Freigabeworkflow).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Warnungen und Hinweise lassen einen Test fehlschlagen
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
	if ((error_reporting() & $severity) === 0) {
		return false;
	}

	throw new ErrorException($message, 0, $severity, $file, $line);
});

$root = dirname(__DIR__);
require __DIR__ . '/support/lib.php';

$args    = array_slice($argv, 1);
$filter  = null;
$groups  = [];

foreach ($args as $arg) {
	if (str_starts_with($arg, '--filter=')) {
		$filter = substr($arg, 9);
	} elseif (in_array($arg, ['--unit', '--http'], true)) {
		$groups[] = substr($arg, 2);
	}
}

$groups = $groups ?: ['unit', 'http'];
$failed = 0;
$passed = 0;
$start  = microtime(true);

foreach ($groups as $group) {
	$files = glob(__DIR__ . '/' . $group . '/*.php') ?: [];
	sort($files);

	if ($group === 'http') {
		require_once __DIR__ . '/support/http.php';
	}

	echo "\n== " . ucfirst($group) . "\n";

	foreach ($files as $file) {
		TestRegistry::$tests = [];
		require $file;

		foreach (TestRegistry::$tests as [$name, $fn]) {
			$label = basename($file, '.php') . ': ' . $name;

			if ($filter !== null && stripos($label, $filter) === false) {
				continue;
			}

			try {
				$fn();
				$passed++;
				echo "  ✔ $label\n";
			} catch (Throwable $e) {
				$failed++;
				$where = $e instanceof AssertionFailed ? '' : ' (' . $e::class . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
				echo "  ✘ $label\n      " . $e->getMessage() . $where . "\n";
			}
		}
	}
}

printf("\n%d bestanden, %d fehlgeschlagen, %d Prüfungen, %.1f s\n", $passed, $failed, TestRegistry::$assertions, microtime(true) - $start);
exit($failed > 0 ? 1 : 0);
