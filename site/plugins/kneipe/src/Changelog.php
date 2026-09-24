<?php

namespace Kneipe;

/**
 * Ermittelt geänderte Felder zwischen zwei Inhaltsständen für das
 * Änderungsprotokoll eines Termins.
 */
final class Changelog
{
	/** Felder, die sich bei jeder Speicherung ändern oder das Protokoll selbst sind */
	public const IGNORE = [
		'uuid', 'modifiedby', 'modifiedat', 'changelog', 'changenote',
		'createdby', 'createdat', 'conflicts',
	];

	public const MAX_ENTRIES = 100;

	/**
	 * @return string[] Namen geänderter Felder (ohne IGNORE)
	 */
	public static function changedFields(array $old, array $new): array
	{
		$old  = array_change_key_case($old, CASE_LOWER);
		$new  = array_change_key_case($new, CASE_LOWER);
		$keys = array_unique([...array_keys($old), ...array_keys($new)]);
		$diff = [];

		foreach ($keys as $key) {
			if (in_array($key, self::IGNORE, true) === true) {
				continue;
			}

			$a = trim((string)($old[$key] ?? ''));
			$b = trim((string)($new[$key] ?? ''));

			if ($a !== $b) {
				$diff[] = $key;
			}
		}

		sort($diff);

		return $diff;
	}

	/**
	 * Neuer Protokolleintrag vorne, Liste auf MAX_ENTRIES gekürzt.
	 */
	public static function prepend(array $entries, array $entry): array
	{
		array_unshift($entries, $entry);
		return array_slice($entries, 0, self::MAX_ENTRIES);
	}
}
