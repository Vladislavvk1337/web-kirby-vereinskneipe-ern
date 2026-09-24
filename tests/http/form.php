<?php

require_once dirname(__DIR__) . '/support/http.php';

$valid = fn () => [
	'slot' => 'anderer', 'wishdate' => date('Y-m-d', strtotime('+30 days')), 'altdate' => '',
	'groupname' => 'HTTP-Testgruppe', 'grouptype' => 'initiative', 'contactname' => 'Test Person',
	'email' => 'http-test@example.org', 'phone' => '', 'intro' => 'Wir testen das Formular.',
	'message' => 'Hallo!', 'privacy' => 'ja', 'website' => '',
];

test('Formular enthält CSRF-Token, Zeitfalle und Honeypot', function () {
	$html = (new HttpClient())->get('/termin-anfragen')['body'];
	$hidden = hidden_fields($html);
	assert_true(strlen($hidden['csrf'] ?? '') > 20, 'CSRF-Token');
	assert_true(isset($hidden['formstart']));
	assert_contains('name="website"', $html);
	assert_contains('novalidate', $html);
});

test('ohne gültiges CSRF-Token wird nichts gespeichert', function () use ($valid) {
	$before = count(request_drafts());
	$http = new HttpClient();
	$http->get('/termin-anfragen');
	$response = $http->post('/termin-anfragen', [...$valid(), 'csrf' => 'falsch', 'formstart' => '1']);
	assert_same(422, $response['status']);
	assert_contains('Sitzung ist abgelaufen', $response['body']);
	assert_contains('value="HTTP-Testgruppe"', $response['body'], 'Eingaben bleiben erhalten');
	assert_same($before, count(request_drafts()));

	// Token als Array führt nicht zu einem Serverfehler
	assert_same(422, $http->post('/termin-anfragen', [...$valid(), 'csrf' => ['x'], 'groupname' => ['y']])['status']);

	// ohne Sitzung (fremde Seite schickt das Formular ab)
	$foreign = (new HttpClient())->post('/termin-anfragen', [...$valid(), 'csrf' => $hidden['csrf'] ?? 'x']);
	assert_same(422, $foreign['status']);
	assert_same($before, count(request_drafts()));
});

test('Anfrage von fremder Herkunft wird abgelehnt', function () use ($valid) {
	$http = new HttpClient();
	$hidden = hidden_fields($http->get('/termin-anfragen')['body']);
	$response = $http->post('/termin-anfragen', [...$valid(), ...$hidden], ['Origin: https://angreifer.example']);
	assert_same(422, $response['status']);
});

test('Honeypot: Bot erhält Bestätigung, gespeichert wird nichts', function () use ($valid) {
	$before = count(request_drafts());
	$http = new HttpClient();
	$hidden = hidden_fields($http->get('/termin-anfragen')['body']);
	$response = $http->post('/termin-anfragen', [...$valid(), ...$hidden, 'website' => 'https://spam.example']);
	assert_same(303, $response['status']);
	assert_same($before, count(request_drafts()));
});

test('manipulierte Zeitfalle wird als Bot gewertet', function () use ($valid) {
	$before = count(request_drafts());
	$http = new HttpClient();
	$hidden = hidden_fields($http->get('/termin-anfragen')['body']);
	$response = $http->post('/termin-anfragen', [...$valid(), ...$hidden, 'formstart' => '1.abc']);
	assert_same(303, $response['status']);
	assert_same($before, count(request_drafts()));

	// Unvollständig und zu schnell: Menschen bekommen trotzdem Fehlermeldungen
	$hidden = hidden_fields($http->get('/termin-anfragen')['body']);
	$response = $http->post('/termin-anfragen', [...$hidden, 'formstart' => '1.abc', 'groupname' => 'Schnell']);
	assert_same(422, $response['status']);
	assert_contains('id="fehleruebersicht"', $response['body']);
});

test('Validierungsfehler stehen am Feld, Eingaben bleiben erhalten', function () use ($valid) {
	$http = new HttpClient();
	$hidden = hidden_fields($http->get('/termin-anfragen')['body']);
	$response = $http->post('/termin-anfragen', [...$valid(), ...$hidden, 'email' => 'kaputt', 'privacy' => '']);
	assert_same(422, $response['status']);
	assert_contains('id="fehleruebersicht"', $response['body']);
	assert_contains('id="feld-email-fehler"', $response['body']);
	assert_contains('aria-invalid="true"', $response['body']);
	assert_contains('aria-describedby="feld-email-hinweis feld-email-fehler"', $response['body']);
	assert_contains('value="kaputt"', $response['body']);
	assert_contains('Wir testen das Formular.', $response['body']);
	assert_contains('id="feld-privacy-fehler"', $response['body']);
});

test('gültige Anfrage: speichern, Post/Redirect/Get ohne Daten in der URL', function () use ($valid) {
	$before = count(request_drafts());
	$http = new HttpClient();
	$hidden = hidden_fields($http->get('/termin-anfragen?termin=beispieltermin-noch-frei-16-10')['body']);
	$response = $http->post('/termin-anfragen', [...$valid(), ...$hidden]);

	assert_same(303, $response['status']);
	$location = $response['headers']['location'][0] ?? '';
	assert_true(str_ends_with($location, '/termin-anfragen/danke'), $location);
	assert_not_contains('?', $location);
	assert_same($before + 1, count(request_drafts()), 'als Entwurf gespeichert');

	$drafts = request_drafts();
	$stored = file_get_contents(end($drafts));
	assert_contains('HTTP-Testgruppe', $stored);
	assert_contains('Processing: neu', $stored);

	$thanks = $http->get('/termin-anfragen/danke');
	assert_same(200, $thanks['status']);
	assert_contains('noindex', $thanks['body']);

	// Anfrage erscheint nirgends öffentlich
	assert_not_contains('HTTP-Testgruppe', $http->get('/termine')['body']);
	assert_not_contains('HTTP-Testgruppe', $http->get('/sitemap.xml')['body']);
});

test('freier Termin kann über den Link vorausgewählt werden', function () {
	$html = (new HttpClient())->get('/termin-anfragen?termin=beispieltermin-noch-frei-16-10')['body'];
	assert_contains('value="beispieltermin-noch-frei-16-10" checked', $html);
});

test('Rate-Limit greift nach der eingestellten Anzahl', function () use ($valid) {
	$http = new HttpClient();
	$statuses = [];

	for ($i = 0; $i < 4; $i++) {
		$hidden = hidden_fields($http->get('/termin-anfragen')['body']);
		$statuses[] = $http->post('/termin-anfragen', [...$valid(), ...$hidden])['status'];
	}

	// Grenze 3 je Stunde; eine gültige Anfrage aus dem vorigen Test zählt mit
	assert_true(in_array(429, $statuses, true), 'kein 429: ' . implode(',', $statuses));
});
