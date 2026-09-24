<?php

namespace Kneipe;

/**
 * Erkennung von Doppelbelegungen.
 *
 * Zwei Termine überschneiden sich, wenn sich ihre Zeiträume [start, end)
 * schneiden. Abgesagte und archivierte Termine zählen nicht.
 */
final class Overlap
{
	public static function intervalsOverlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
	{
		return $aStart < $bEnd && $bStart < $aEnd;
	}

	/**
	 * @param array $candidate ['id', 'start', 'end', 'orgstatus']
	 * @param array $others    Liste gleicher Struktur
	 * @return array Überschneidende Termine aus $others
	 */
	public static function conflicts(array $candidate, array $others): array
	{
		if (EventStatus::isActive($candidate['orgstatus'] ?? '') === false) {
			return [];
		}

		return array_values(array_filter($others, function (array $other) use ($candidate): bool {
			if (($other['id'] ?? null) === ($candidate['id'] ?? null)) {
				return false;
			}

			if (EventStatus::isActive($other['orgstatus'] ?? '') === false) {
				return false;
			}

			return self::intervalsOverlap(
				$candidate['start'],
				$candidate['end'],
				$other['start'],
				$other['end']
			);
		}));
	}
}
