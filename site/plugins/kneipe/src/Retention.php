<?php

namespace Kneipe;

use Kirby\Cms\App;

/**
 * Löschfrist für Terminanfragen: Anfragen, die älter als die im Panel
 * eingestellte Frist sind, werden endgültig gelöscht.
 *
 * Läuft bei jeder neuen Anfrage und täglich per systemd-Timer
 * (bin/cleanup-requests.php).
 */
final class Retention
{
	/** @return int Anzahl gelöschter Anfragen */
	public static function cleanup(App $kirby, int $days, int|null $now = null): int
	{
		$now    = $now ?? time();
		$limit  = $now - $days * 86400;
		$parent = $kirby->site()->find('anfragen');

		if ($parent === null) {
			return 0;
		}

		$deleted = 0;

		foreach ($parent->drafts() as $request) {
			$submitted = strtotime((string)$request->content()->get('submittedat')->value()) ?: $request->modified();

			if ($submitted < $limit) {
				$kirby->impersonate('kirby', fn () => $request->delete(true));
				$deleted++;
			}
		}

		return $deleted;
	}
}
