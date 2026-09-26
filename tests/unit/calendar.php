<?php

use Grav\Plugin\Kneipe\Calendar;

$ts = fn (string $date) => strtotime($date);
$item = fn (string $id, string $start, string|null $end = null, bool $allDay = false, array $extra = []) => [
	'id'     => $id,
	'title'  => $id,
	'start'  => $ts($start),
	'end'    => Calendar::effectiveEnd($ts($start), $end ? $ts($end) : null, $allDay),
	'allday' => $allDay,
	...$extra,
];

test('kommende Termine werden chronologisch sortiert', function () use ($item, $ts) {
	$events = [
		$item('c', '2026-11-20 19:00'),
		$item('a', '2026-10-02 19:00'),
		$item('vergangen', '2026-08-01 19:00'),
		$item('b', '2026-10-16 19:00'),
	];

	$upcoming = Calendar::upcoming($events, $ts('2026-09-24 12:00'));
	assert_same(['a', 'b', 'c'], array_column($upcoming, 'id'));
});

test('gleicher Beginn wird nach Titel sortiert', function () use ($item) {
	$sorted = Calendar::sort([$item('b', '2026-10-02 19:00'), $item('a', '2026-10-02 19:00')]);
	assert_same(['a', 'b'], array_column($sorted, 'id'));
});

test('vergangene Termine werden erkannt – maßgeblich ist das Ende', function () use ($item, $ts) {
	$now = $ts('2026-10-02 21:00');
	assert_false(Calendar::isPast($item('laufend', '2026-10-02 19:00', '2026-10-02 23:00'), $now), 'laufender Termin ist nicht vergangen');
	assert_true(Calendar::isPast($item('vorbei', '2026-10-02 15:00', '2026-10-02 17:00'), $now));
	assert_same(['vorbei'], array_column(Calendar::past([
		$item('laufend', '2026-10-02 19:00', '2026-10-02 23:00'),
		$item('vorbei', '2026-10-02 15:00', '2026-10-02 17:00'),
	], $now), 'id'));
});

test('fehlendes Ende = 4 Stunden, ganztägig = bis Mitternacht', function () use ($ts) {
	assert_same($ts('2026-10-02 23:00'), Calendar::effectiveEnd($ts('2026-10-02 19:00'), null, false));
	assert_same($ts('2026-10-02 23:00'), Calendar::effectiveEnd($ts('2026-10-02 19:00'), $ts('2026-10-02 18:00'), false), 'Ende vor Beginn');
	assert_same($ts('2026-12-25 00:00'), Calendar::effectiveEnd($ts('2026-12-24 00:00'), null, true));
	assert_same($ts('2026-12-27 00:00'), Calendar::effectiveEnd($ts('2026-12-24 00:00'), $ts('2026-12-26 00:00'), true), 'mehrtägig');
});

test('Filter nach Terminart und Verfügbarkeit', function () use ($item) {
	$events = [
		$item('frei', '2026-10-16 19:00', null, false, ['category' => 'kneipenabend', 'public' => 'frei']),
		$item('kultur', '2026-10-23 19:00', null, false, ['category' => 'kultur', 'public' => 'bestaetigt']),
		$item('abgesagt', '2026-11-06 19:00', null, false, ['category' => 'kneipenabend', 'public' => 'abgesagt']),
	];

	assert_same(['frei'], array_column(Calendar::filter($events, '', 'frei'), 'id'));
	assert_same(['kultur'], array_column(Calendar::filter($events, 'kultur'), 'id'));
	assert_same(['frei', 'abgesagt'], array_column(Calendar::filter($events, 'kneipenabend'), 'id'));
	assert_same([], Calendar::filter($events, 'kultur', 'frei'));
});

test('Monatsraster beginnt montags und enthält alle Tage', function () {
	$weeks = Calendar::monthGrid(2026, 10);
	assert_same('2026-09-28', $weeks[0][0]['date'], 'erster Montag');
	assert_same(5, count($weeks));
	$inMonth = array_filter(array_merge(...$weeks), fn ($day) => $day['inMonth']);
	assert_same(31, count($inMonth));
});

test('Monatsraster über die Zeitumstellung hat 7 Tage je Woche', function () {
	foreach (Calendar::monthGrid(2026, 3) as $week) {
		assert_same(7, count($week));
	}
	assert_same('2026-03-30', Calendar::monthGrid(2026, 3)[5][0]['date']);
});

test('Monatsnavigation und Eingabeprüfung', function () use ($ts) {
	assert_same([2027, 1], Calendar::shiftMonth(2026, 12, 1));
	assert_same([2025, 12], Calendar::shiftMonth(2026, 1, -1));
	$now = $ts('2026-09-24 12:00');
	assert_same([2026, 9], Calendar::normalizeMonth('abc', '13', $now), 'ungültig → aktueller Monat');
	assert_same([2026, 9], Calendar::normalizeMonth('1999', '5', $now), 'außerhalb ±5 Jahre');
	assert_same([2027, 2], Calendar::normalizeMonth('2027', '2', $now));
});

test('Zeitraum-Filter between() berücksichtigt Überlappung', function () use ($item, $ts) {
	$events = [$item('nacht', '2026-09-30 22:00', '2026-10-01 02:00'), $item('nov', '2026-11-01 19:00')];
	[$from, $to] = Calendar::monthRange(2026, 10);
	assert_same(['nacht'], array_column(Calendar::between($events, $from, $to), 'id'));
});
