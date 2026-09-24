<?php

use Kneipe\Calendar;
use Kneipe\EventStatus;

return function ($page, $kirby) {
	$request = $kirby->request();
	$now     = time();

	$category     = (string)$request->get('art', '');
	$availability = (string)$request->get('verfuegbarkeit', '');
	$period       = $request->get('zeitraum') === 'vergangen' ? 'vergangen' : '';
	$hasMonth     = $request->get('monat') !== null || $request->get('jahr') !== null;

	if (isset(EventStatus::CATEGORIES[$category]) === false) {
		$category = '';
	}

	if (isset(EventStatus::PUBLIC[$availability]) === false) {
		$availability = '';
	}

	[$year, $month] = Calendar::normalizeMonth($request->get('jahr'), $request->get('monat'), $now);
	[$from, $to]    = Calendar::monthRange($year, $month);

	$filters = [
		'art'            => $category,
		'verfuegbarkeit' => $availability,
		'jahr'           => $hasMonth ? (string)$year : '',
		'monat'          => $hasMonth ? (string)$month : '',
		'zeitraum'       => $period,
	];

	$urlFor = function (int $y, int $m) use ($page, $category, $availability): string {
		return $page->url() . '?' . http_build_query(array_filter([
			'jahr'           => $y,
			'monat'          => $m,
			'art'            => $category,
			'verfuegbarkeit' => $availability,
		])) . '#kalender';
	};

	$monthEvents = kneipe()->calendarEvents($from, $to, $category, $availability);

	if ($period === 'vergangen') {
		$listEvents = kneipe()->calendarEvents(null, null, $category, $availability, true);
		$listTitle  = 'Rückblick: vergangene Termine';
	} elseif ($hasMonth) {
		$listEvents = $monthEvents;
		$listTitle  = 'Termine im ' . Calendar::monthLabel($year, $month);
	} else {
		$listEvents = kneipe()->calendarEvents(null, null, $category, $availability);
		$listTitle  = 'Kommende Termine';
	}

	// Gruppierung der Liste nach Monaten
	$groups = [];

	foreach ($listEvents as $event) {
		$key = date('Y-n', $event->startTimestamp());
		$groups[$key] ??= [
			'label'  => Calendar::monthLabel((int)date('Y', $event->startTimestamp()), (int)date('n', $event->startTimestamp())),
			'events' => [],
		];
		$groups[$key]['events'][] = $event;
	}

	return [
		'year'        => $year,
		'month'       => $month,
		'filters'     => $filters,
		'urlFor'      => $urlFor,
		'monthEvents' => $monthEvents,
		'listEvents'  => $listEvents,
		'listTitle'   => $listTitle,
		'groups'      => $groups,
		'past'        => $period === '' ? kneipe()->pastEvents(4) : null,
		'styles'      => ['calendar'],
		// gefilterte Ansichten nicht indexieren, Canonical auf die Grundseite
		'noindex'     => array_filter($filters) !== [],
		'canonical'   => $page->url(),
	];
};
