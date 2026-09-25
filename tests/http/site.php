<?php

$public = [
	'/', '/termine', '/termine?jahr=2026&monat=10', '/termine?art=kultur&verfuegbarkeit=bestaetigt',
	'/termine?zeitraum=vergangen', '/termine/kneipenabend-mit-dem-beispiel-thekenteam',
	'/termine/beispieltermin-noch-frei-16-10', '/termine/abend-faellt-aus', '/termine/private-feier',
	'/termine/sommerabend-an-der-theke', '/termin-anfragen', '/termin-anfragen/danke', '/thekenteams',
	'/thekenteams/beispiel-thekenteam', '/thekenteams/beispiel-initiative', '/mitmachen', '/ueber-uns',
	'/kontakt', '/aktuelles', '/aktuelles/rueckblick-sommerabend', '/aktuelles/thekenteams-gesucht',
	'/impressum', '/datenschutz', '/barrierefreiheit', '/bausteine',
];

test('öffentliche Seiten antworten mit 200', function () use ($public) {
	$http = new HttpClient();
	foreach ($public as $path) {
		assert_same(200, $http->get($path)['status'], $path);
	}
});

test('404 für Unbekanntes, Entwürfe, interne Termine und Anfragen', function () {
	$http = new HttpClient();
	foreach ([
		'/gibt-es-nicht', '/termine/demo-reserviert', '/termine/demo-termin-zur-freigabe',
		'/termine/demo-doppelbelegung', '/thekenteams/beispiel-unternehmen', '/anfragen',
		'/anfragen/irgendwas', '/anfragen.json', '/termine/beispieltermin-noch-frei-16-10.ics',
		'/termine/private-feier.ics', '/termine/demo-reserviert.ics',
	] as $path) {
		$response = $http->get($path);
		assert_same(404, $response['status'], $path);
	}

	$error = $http->get('/gibt-es-nicht');
	assert_contains('Seite nicht gefunden', $error['body']);
	assert_contains('noindex', $error['body']);
});

test('interne Felder und Details erscheinen nirgends öffentlich', function () use ($public) {
	$http  = new HttpClient();
	$paths = [...$public, '/termine.ics', '/termine/kneipenabend-mit-dem-beispiel-thekenteam.ics', '/sitemap.xml', '/robots.txt'];

	preg_match_all('!<loc>https?://[^/]+([^<]*)</loc>!', $http->get('/sitemap.xml')['body'], $m);
	$paths = array_unique([...$paths, ...$m[1]]);

	foreach ($paths as $path) {
		$body = $http->get($path)['body'];
		// Alle internen Demo-Felder tragen die Markierung XINTERNX
		assert_not_contains('XINTERNX', $body, $path);
		assert_not_contains('demo-termin-zur-freigabe', $body, $path);
		assert_not_contains('Beispiel-Unternehmen', $body, $path);
	}
});

test('iCalendar-Feed und Einzeltermin', function () {
	$http = new HttpClient();
	$feed = $http->get('/termine.ics');
	assert_same(200, $feed['status']);
	assert_contains('text/calendar', $feed['headers']['content-type'][0]);
	assert_true(str_starts_with($feed['body'], "BEGIN:VCALENDAR\r\n"));
	assert_contains("END:VCALENDAR\r\n", $feed['body']);
	assert_contains('STATUS:CANCELLED', $feed['body'], 'abgesagte Termine sind enthalten');
	assert_same(substr_count($feed['body'], 'BEGIN:VEVENT'), substr_count($feed['body'], 'END:VEVENT'));
	assert_not_contains('noch frei', $feed['body'], 'freie Termine nicht im Feed');
	assert_not_contains('Private Feier', $feed['body']);

	$single = $http->get('/termine/kneipenabend-mit-dem-beispiel-thekenteam.ics');
	assert_same(200, $single['status']);
	assert_contains('attachment', $single['headers']['content-disposition'][0] ?? '');
	assert_same(1, substr_count($single['body'], 'BEGIN:VEVENT'));
	assert_contains('DTSTART:20261002T170000Z', $single['body']);
});

test('robots.txt und XML-Sitemap', function () {
	$http   = new HttpClient();
	$robots = $http->get('/robots.txt')['body'];
	assert_contains('Sitemap:', $robots);
	assert_contains('Disallow: /admin', $robots);

	$sitemap = $http->get('/sitemap.xml')['body'];
	assert_true(simplexml_load_string($sitemap) !== false, 'gültiges XML');
	assert_contains('/termine/kneipenabend-mit-dem-beispiel-thekenteam', $sitemap);
	foreach (['/anfragen<', '/bausteine', '/danke', 'demo-reserviert', 'beispiel-unternehmen', '/error'] as $hidden) {
		assert_not_contains($hidden, $sitemap);
	}
});

test('nur das Anfrageformular setzt ein (technisch notwendiges) Cookie', function () {
	foreach (['/', '/termine', '/termine/kneipenabend-mit-dem-beispiel-thekenteam', '/kontakt'] as $path) {
		$response = (new HttpClient())->get($path);
		assert_same([], $response['headers']['set-cookie'] ?? [], "$path setzt Cookies");
	}

	$form = (new HttpClient())->get('/termin-anfragen');
	assert_contains('kneipe', implode(' ', $form['headers']['set-cookie'] ?? []));
	assert_contains('HttpOnly', implode(' ', $form['headers']['set-cookie']));
});

test('Kalender: ungültige Parameter führen nicht zu Fehlern', function () {
	$http = new HttpClient();
	foreach (['/termine?jahr=abc&monat=99', '/termine?art=<script>', '/termine?verfuegbarkeit[]=x', '/termine?jahr[]=1', '/termin-anfragen?termin[]=x', '/termine?zeitraum=x'] as $path) {
		$response = $http->get($path);
		assert_same(200, $response['status'], $path);
		assert_not_contains('<script>', $response['body']);
	}

	$filtered = $http->get('/termine?verfuegbarkeit=frei');
	assert_contains('Termin frei', $filtered['body']);
	assert_contains('noindex', $filtered['body'], 'gefilterte Ansicht nicht indexieren');
});

test('Health-Checks für Kubernetes', function () {
	$http = new HttpClient();

	foreach (['/healthz', '/readyz'] as $path) {
		$response = $http->get($path);
		assert_same(200, $response['status'], $path);
		assert_same("ok\n", $response['body'], $path);
		assert_contains('no-store', implode(' ', $response['headers']['cache-control'] ?? []), $path);
		assert_same([], $response['headers']['set-cookie'] ?? [], "$path setzt keine Cookies");
	}
});
