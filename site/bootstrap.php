<?php

/**
 * Gemeinsamer Start für index.php, CLI-Skripte (bin/) und Tests.
 *
 * - liest Umgebungsvariablen aus einer .env-Datei (lokal: Projektwurzel,
 *   Server: Pfad aus KIRBY_ENV_FILE, gesetzt im PHP-FPM-Pool)
 * - legt die Kirby-Roots fest; auf dem Server liegen Inhalte, Konten,
 *   Sitzungen, Cache und Medien außerhalb des Programmcodes
 *
 * Werte, die der Webserver bzw. PHP-FPM bereits setzt, haben Vorrang vor
 * der .env-Datei.
 */

if (function_exists('kneipe_env') === false) {
	/**
	 * Liest eine einfache .env-Datei (KEY=VALUE, # Kommentare, optionale
	 * Anführungszeichen). Keine Variablen-Expansion, keine Mehrzeiler.
	 */
	function kneipe_load_env(string $file): void
	{
		if (is_file($file) === false || is_readable($file) === false) {
			return;
		}

		foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
			$line = trim($line);

			if ($line === '' || str_starts_with($line, '#') === true) {
				continue;
			}

			if (str_starts_with($line, 'export ') === true) {
				$line = substr($line, 7);
			}

			$pos = strpos($line, '=');

			if ($pos === false) {
				continue;
			}

			$key   = trim(substr($line, 0, $pos));
			$value = trim(substr($line, $pos + 1));

			if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
				continue;
			}

			if (
				strlen($value) >= 2 &&
				($value[0] === '"' || $value[0] === "'") &&
				$value[-1] === $value[0]
			) {
				$value = substr($value, 1, -1);
			} else {
				// Kommentar am Zeilenende nur bei Werten ohne Anführungszeichen
				$value = trim(preg_replace('/\s+#.*$/', '', $value));
			}

			// bereits gesetzte Werte (Server, Tests) nicht überschreiben
			if (getenv($key) !== false || isset($_ENV[$key]) === true) {
				continue;
			}

			putenv($key . '=' . $value);
			$_ENV[$key] = $value;
		}
	}

	function kneipe_env(string $key, string|null $default = null): string|null
	{
		$value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

		if ($value === false || $value === null || $value === '') {
			return $default;
		}

		return (string)$value;
	}

	function kneipe_env_bool(string $key, bool $default = false): bool
	{
		$value = kneipe_env($key);

		if ($value === null) {
			return $default;
		}

		return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'ja'], true);
	}

	/**
	 * Kirby-Roots. Ohne Umgebungsvariablen gilt die Plainkit-Struktur
	 * (content/, site/accounts/, site/sessions/, site/cache/, media/).
	 */
	function kneipe_roots(string $base): array
	{
		$roots = [
			'index' => $base,
			'base'  => $base,
			'site'  => $base . '/site',
		];

		if ($content = kneipe_env('KIRBY_CONTENT_ROOT')) {
			$roots['content'] = $content;
		}

		if ($media = kneipe_env('KIRBY_MEDIA_ROOT')) {
			$roots['media'] = $media;
		}

		// Laufzeitdaten: Konten, Sitzungen, Cache, Protokolle
		if ($storage = kneipe_env('KIRBY_STORAGE_ROOT')) {
			$roots['accounts'] = $storage . '/accounts';
			$roots['sessions'] = $storage . '/sessions';
			$roots['cache']    = $storage . '/cache';
			$roots['logs']     = $storage . '/logs';
			$roots['license']  = $storage . '/.license';
		}

		return $roots;
	}
}

kneipe_load_env(kneipe_env('KIRBY_ENV_FILE') ?? dirname(__DIR__) . '/.env');

date_default_timezone_set(kneipe_env('KIRBY_TIMEZONE', 'Europe/Berlin'));
