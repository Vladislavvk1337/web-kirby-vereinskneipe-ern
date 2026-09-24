<?php

require_once dirname(__DIR__) . '/support/kirby.php';

test('kommende öffentliche Termine sind chronologisch und ohne Entwürfe', function () {
	$kirby  = fresh_kirby();
	$events = kneipe()->upcomingEvents(null, true, true, TEST_NOW);
	$starts = array_map(fn ($e) => $e->startTimestamp(), $events->values());
	$sorted = $starts;
	sort($sorted);

	assert_same($sorted, $starts, 'aufsteigend sortiert');
	assert_true($events->count() >= 8);

	foreach ($events as $event) {
		assert_false($event->isDraft(), $event->id() . ' ist ein Entwurf');
		assert_true($event->isPublic(), $event->id() . ' ist nicht öffentlich');
	}
});

test('vergangene Termine wechseln in den Rückblick', function () {
	$kirby = fresh_kirby();
	assert_true(event($kirby, 'sommerabend-an-der-theke')->isPast(TEST_NOW));
	assert_false(event($kirby, 'kneipenabend-mit-dem-beispiel-thekenteam')->isPast(TEST_NOW));

	$past = kneipe()->pastEvents(null, TEST_NOW)->keys();
	assert_same(['termine/kneipenabend-september', 'termine/sommerabend-an-der-theke'], $past, 'neueste zuerst, ohne freie Termine');
	assert_false(in_array('termine/sommerabend-an-der-theke', kneipe()->upcomingEvents(null, true, true, TEST_NOW)->keys(), true));
});

test('nächster Öffnungstermin und nächstes Thekenteam', function () {
	$kirby = fresh_kirby();
	$next  = kneipe()->nextOpening(TEST_NOW);
	assert_same('termine/kneipenabend-mit-dem-beispiel-thekenteam', $next?->id());
	assert_same('thekenteams/beispiel-thekenteam', $next->publicTeam()?->id());
});

test('Statusfilter: interne Status, Entwürfe und private Details bleiben verborgen', function () {
	$kirby  = fresh_kirby();
	$public = kneipe()->publicEvents()->keys();

	foreach (['termine/demo-reserviert', 'termine/demo-termin-zur-freigabe', 'termine/demo-doppelbelegung'] as $id) {
		assert_false(in_array($id, $public, true), "$id darf nicht öffentlich sein");
	}

	$private = event($kirby, 'private-feier');
	assert_true($private->isPublic());
	assert_same('geschlossen', $private->publicStatus());
	assert_same('Geschlossene Gesellschaft', $private->publicTitle());
	assert_not_contains('XINTERNX', $private->publicTeaser() . json_encode($private->icsData()));

	$free = kneipe()->freeEvents(null, TEST_NOW);
	assert_same(3, $free->count());
	foreach ($free as $event) {
		assert_same('frei', $event->publicStatus());
	}

	// Ein bestätigter Termin, der intern auf „reserviert“ gesetzt wird, verschwindet
	$confirmed = event($kirby, 'liederabend');
	$kirby->impersonate('kirby', fn () => $confirmed->save(['orgstatus' => 'reserviert']));
	assert_false(event($kirby, 'liederabend')->isPublic());
});

test('Team ohne Einwilligung erscheint nirgends öffentlich', function () {
	$kirby = fresh_kirby();
	$event = event($kirby, 'demo-termin-zur-freigabe');
	assert_same(null, $event->publicTeam());
	assert_false($kirby->site()->find('thekenteams')->findPageOrDraft('beispiel-unternehmen')->isPublic());
});

test('Überschneidungen werden erkannt und im Dashboard gemeldet', function () {
	$kirby     = fresh_kirby();
	$conflicts = event($kirby, 'kneipenabend-mit-dem-beispiel-thekenteam')->conflicts()->keys();
	assert_same(['termine/demo-doppelbelegung'], $conflicts);
	assert_contains('Überschneidung', event($kirby, 'demo-doppelbelegung')->conflictInfo());
	assert_same(0, event($kirby, 'liederabend')->conflicts()->count());
	assert_true(in_array('termine/demo-doppelbelegung', kneipe()->conflictingEvents()->keys(), true));
});

test('iCalendar-Daten enthalten nur öffentliche Angaben', function () {
	$kirby = fresh_kirby();
	$data  = json_encode(array_map(fn ($e) => $e->icsData(), kneipe()->publicEvents()->values()));
	assert_not_contains('XINTERNX', $data);
	assert_true(event($kirby, 'kneipenabend-mit-dem-beispiel-thekenteam')->hasIcs());
	assert_false(event($kirby, 'beispieltermin-noch-frei-16-10')->hasIcs(), 'freie Termine nicht als Kalendereintrag');
	assert_same('CANCELLED', event($kirby, 'abend-faellt-aus')->icsData()['status']);
});
