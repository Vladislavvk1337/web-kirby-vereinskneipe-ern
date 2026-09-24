<?php

namespace Kneipe;

/**
 * Zeitfalle für Formulare: Ein signierter Zeitstempel im versteckten Feld
 * zeigt, wann das Formular ausgeliefert wurde. Wer schneller absendet als
 * ein Mensch tippen kann, ist sehr wahrscheinlich ein Bot.
 */
final class FormTimer
{
	public static function issue(string $secret, int|null $now = null): string
	{
		$now = $now ?? time();
		return $now . '.' . self::sign((string)$now, $secret);
	}

	/**
	 * Sekunden seit Ausgabe des Formulars oder null bei ungültigem Token.
	 * Tokens älter als $maxAge gelten als ungültig.
	 */
	public static function elapsed(string $token, string $secret, int|null $now = null, int $maxAge = 86400): int|null
	{
		$now   = $now ?? time();
		$parts = explode('.', $token, 2);

		if (count($parts) !== 2 || ctype_digit($parts[0]) === false) {
			return null;
		}

		if (hash_equals(self::sign($parts[0], $secret), $parts[1]) === false) {
			return null;
		}

		$elapsed = $now - (int)$parts[0];

		if ($elapsed < 0 || $elapsed > $maxAge) {
			return null;
		}

		return $elapsed;
	}

	private static function sign(string $value, string $secret): string
	{
		return substr(hash_hmac('sha256', 'form-timer|' . $value, $secret), 0, 32);
	}
}
