<?php

namespace Kneipe;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reine Kalenderlogik ohne Kirby-Abhängigkeit.
 *
 * Termine werden als Arrays verarbeitet:
 *   ['id' => string, 'start' => int, 'end' => int, 'allday' => bool,
 *    'category' => string, 'public' => string|null, ...]
 * „start“/„end“ sind Unix-Zeitstempel; „end“ ist bereits das effektive
 * Ende (siehe effectiveEnd()).
 */
final class Calendar
{
	/** Angenommene Dauer, wenn kein Ende gepflegt ist (Stunden) */
	public const DEFAULT_DURATION = 4;

	public const MONTHS = [
		1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
		'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
	];

	public const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

	public const WEEKDAYS_LONG = [
		'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag',
	];

	/**
	 * Effektives Ende: ganztägig = Beginn des Folgetags, fehlendes oder
	 * unplausibles Ende = Beginn + Standarddauer.
	 */
	public static function effectiveEnd(int $start, int|null $end, bool $allDay, DateTimeZone|null $tz = null): int
	{
		if ($allDay === true) {
			$day = (new DateTimeImmutable('@' . $start))
				->setTimezone($tz ?? new DateTimeZone(date_default_timezone_get()))
				->setTime(0, 0);

			$endDay = $end !== null && $end > $start
				? (new DateTimeImmutable('@' . $end))->setTimezone($day->getTimezone())->setTime(0, 0)
				: $day;

			return $endDay->add(new DateInterval('P1D'))->getTimestamp();
		}

		if ($end === null || $end <= $start) {
			return $start + self::DEFAULT_DURATION * 3600;
		}

		return $end;
	}

	public static function isPast(array $event, int $now): bool
	{
		return $event['end'] <= $now;
	}

	/** Chronologisch aufsteigend; bei gleichem Beginn nach Titel */
	public static function sort(array $events, bool $descending = false): array
	{
		usort($events, function (array $a, array $b) use ($descending): int {
			$result = $a['start'] <=> $b['start'] ?: strcmp($a['title'] ?? '', $b['title'] ?? '');
			return $descending ? -$result : $result;
		});

		return $events;
	}

	public static function upcoming(array $events, int $now): array
	{
		return self::sort(array_values(array_filter(
			$events,
			fn (array $event): bool => self::isPast($event, $now) === false
		)));
	}

	public static function past(array $events, int $now): array
	{
		return self::sort(array_values(array_filter(
			$events,
			fn (array $event): bool => self::isPast($event, $now) === true
		)), true);
	}

	/**
	 * Filter nach Terminart und Verfügbarkeit.
	 *
	 * $availability: '' (alle), 'frei', 'bestaetigt', 'abgesagt', 'geschlossen'
	 */
	public static function filter(array $events, string $category = '', string $availability = ''): array
	{
		return array_values(array_filter($events, function (array $event) use ($category, $availability): bool {
			if ($category !== '' && ($event['category'] ?? '') !== $category) {
				return false;
			}

			if ($availability !== '' && ($event['public'] ?? null) !== $availability) {
				return false;
			}

			return true;
		}));
	}

	/** Termine, die den Zeitraum [from, to) berühren */
	public static function between(array $events, int $from, int $to): array
	{
		return array_values(array_filter(
			$events,
			fn (array $event): bool => $event['start'] < $to && $event['end'] > $from
		));
	}

	/**
	 * Monatsraster (Montag zuerst). Liefert Wochen mit je 7 Tagen:
	 *   ['date' => 'Y-m-d', 'day' => int, 'inMonth' => bool, 'start' => int, 'end' => int]
	 */
	public static function monthGrid(int $year, int $month, DateTimeZone|null $tz = null): array
	{
		$tz    = $tz ?? new DateTimeZone(date_default_timezone_get());
		$first = (new DateTimeImmutable('now', $tz))->setDate($year, $month, 1)->setTime(0, 0);
		$shift = (int)$first->format('N') - 1;
		$day   = $first->sub(new DateInterval('P' . $shift . 'D'));
		$weeks = [];

		do {
			$week = [];

			for ($i = 0; $i < 7; $i++) {
				$next   = $day->add(new DateInterval('P1D'));
				$week[] = [
					'date'    => $day->format('Y-m-d'),
					'day'     => (int)$day->format('j'),
					'inMonth' => (int)$day->format('n') === $month,
					'start'   => $day->getTimestamp(),
					'end'     => $next->getTimestamp(),
				];
				$day = $next;
			}

			$weeks[] = $week;
		} while ((int)$day->format('n') === $month);

		return $weeks;
	}

	/** Beginn und Ende (exklusiv) eines Monats als Zeitstempel */
	public static function monthRange(int $year, int $month, DateTimeZone|null $tz = null): array
	{
		$tz    = $tz ?? new DateTimeZone(date_default_timezone_get());
		$first = (new DateTimeImmutable('now', $tz))->setDate($year, $month, 1)->setTime(0, 0);

		return [$first->getTimestamp(), $first->add(new DateInterval('P1M'))->getTimestamp()];
	}

	/**
	 * Monat aus Anfrageparametern – ungültige Werte fallen auf den
	 * aktuellen Monat zurück. Erlaubt ±5 Jahre um heute.
	 */
	public static function normalizeMonth(mixed $year, mixed $month, int $now): array
	{
		$currentYear  = (int)date('Y', $now);
		$currentMonth = (int)date('n', $now);

		$year  = filter_var($year, FILTER_VALIDATE_INT, ['options' => ['min_range' => $currentYear - 5, 'max_range' => $currentYear + 5]]);
		$month = filter_var($month, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);

		if ($year === false || $month === false) {
			return [$currentYear, $currentMonth];
		}

		return [$year, $month];
	}

	/** [Jahr, Monat] verschoben um $delta Monate */
	public static function shiftMonth(int $year, int $month, int $delta): array
	{
		$index = $year * 12 + ($month - 1) + $delta;
		return [intdiv($index, 12), $index % 12 + 1];
	}

	public static function monthLabel(int $year, int $month): string
	{
		return self::MONTHS[$month] . ' ' . $year;
	}
}
