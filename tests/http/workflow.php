<?php

/**
 * Rollen und Freigabeworkflow über die Admin-API (so arbeitet Admin2).
 * Jede Regel wird serverseitig geprüft – unabhängig davon, was die
 * Oberfläche anbietet.
 */

$pageHead = fn (string $route) => page_header((string)TestServer::pageFile($route));

test('Anmeldung: beide Rollen erhalten ein Token, falsches Passwort nicht', function () {
	ApiClient::admin();
	ApiClient::moderation();
	$wrong = (new HttpClient())->request('POST', '/api/v1/auth/token', json_encode(['username' => 'admin', 'password' => 'falsch']), ['Content-Type: application/json']);
	assert_true(in_array($wrong['status'], [401, 403], true), 'HTTP ' . $wrong['status']);
});

test('Moderation: keine Konten anderer, keine Einstellungen, keine Rechtstexte', function () use ($pageHead) {
	$mod = ApiClient::moderation();

	$users = $mod->call('GET', '/users');
	$names = array_column($users['json']['data'] ?? [], 'username');
	assert_same(['moderation'], $names, 'nur das eigene Konto sichtbar');
	assert_same(403, $mod->call('GET', '/users/admin')['status'], 'fremdes Konto');
	assert_same(403, $mod->call('POST', '/users', ['username' => 'neu', 'email' => 'neu@example.org', 'password' => 'Neues-Passwort-123', 'access' => ['api' => ['super' => true]]])['status']);

	assert_same(403, $mod->update('/einstellungen', ['approvalmode' => false])['status'], 'Einstellungen nicht änderbar');
	assert_true($pageHead('/einstellungen')['approvalmode']);
	assert_same(403, $mod->update('/impressum', ['title' => 'Geändert'])['status'] ?? 403);
	assert_same('Impressum', $pageHead('/impressum')['title']);

	// Übersichtsseiten und Startseite schützt das Plugin
	assert_same(403, $mod->update('/termine', ['intro' => 'geändert'])['status']);
	assert_same(403, $mod->update('/home', ['intro' => 'geändert'])['status']);
});

test('Moderation kann im Freigabemodus nicht bestätigen, aber zur Freigabe vorbereiten', function () use ($pageHead) {
	$mod = ApiClient::moderation();

	foreach (['bestaetigt', 'veroeffentlicht'] as $status) {
		$response = $mod->update('/termine/demo-reserviert', ['orgstatus' => $status]);
		assert_same(403, $response['status'], $status);
		assert_contains('Freigabemodus', $response['body']);
	}

	assert_same('reserviert', $pageHead('/termine/demo-reserviert')['orgstatus']);

	$ok = $mod->update('/termine/demo-reserviert', ['orgstatus' => 'freigabe', 'teaser' => 'Vorbereitet', 'changenote' => 'Bitte freigeben']);
	assert_same(200, $ok['status'], $ok['body']);

	$header = $pageHead('/termine/demo-reserviert');
	assert_same('freigabe', $header['orgstatus']);
	assert_same('Test Moderation (moderation)', $header['modifiedby']);
	assert_same('Bitte freigeben', $header['changelog'][0]['note']);
	assert_contains('orgstatus', $header['changelog'][0]['changes']);
	assert_false(isset($header['changenote']), 'Änderungsnotiz wandert ins Protokoll');
});

test('Moderation kann im Freigabemodus nicht veröffentlichen – auch nicht per Stapel', function () use ($pageHead) {
	$mod = ApiClient::moderation();

	$single = $mod->update('/termine/demo-termin-zur-freigabe', ['published' => true]);
	assert_same(403, $single['status']);

	$direct = $mod->update('/termine/demo-termin-zur-freigabe', [], ['published' => true]);
	assert_same(403, $direct['status'], 'published im Body');

	$batch = $mod->call('POST', '/pages/batch', ['operation' => 'publish', 'routes' => ['/termine/demo-termin-zur-freigabe']]);
	assert_not_contains('"status":"success"', $batch['body'], 'Stapel-Veröffentlichung');

	assert_false($pageHead('/termine/demo-termin-zur-freigabe')['published']);
	assert_same(404, (new HttpClient())->get('/termine/demo-termin-zur-freigabe')['status']);
});

test('Moderation darf Überschneidungen nicht selbst zulassen und Seitenrechte nicht ändern', function () use ($pageHead) {
	$mod = ApiClient::moderation();
	assert_same(403, $mod->update('/termine/demo-doppelbelegung', ['conflictaccepted' => true])['status']);
	assert_same(403, $mod->update('/termine/demo-reserviert', ['permissions' => ['groups' => ['moderation' => 'crudp']]])['status']);
	assert_false($pageHead('/termine/demo-doppelbelegung')['conflictaccepted']);
});

test('neue Termine der Moderation starten unveröffentlicht, mit Metadaten', function () use ($pageHead) {
	$mod = ApiClient::moderation();
	$created = $mod->call('POST', '/pages', [
		'route'    => '/termine/api-testtermin',
		'title'    => 'API-Testtermin',
		'template' => 'event',
		'header'   => ['published' => true, 'orgstatus' => 'freigabe', 'category' => 'kultur', 'start' => '2027-03-05 19:00'],
		'content'  => 'Beschreibung',
	]);
	assert_same(201, $created['status'], $created['body']);

	$header = $pageHead('/termine/api-testtermin');
	assert_false($header['published']);
	assert_same('Test Moderation (moderation)', $header['createdby']);
	assert_same('angelegt', $header['changelog'][0]['changes']);
	assert_true(strlen($header['uuid']) === 16);

	// bestätigt anlegen geht nicht
	$confirmed = $mod->call('POST', '/pages', ['route' => '/termine/api-bestaetigt', 'title' => 'x', 'template' => 'event', 'header' => ['orgstatus' => 'bestaetigt', 'start' => '2027-03-06 19:00']]);
	assert_same(403, $confirmed['status']);

	// andere Seitenarten und Orte nicht
	assert_same(403, $mod->call('POST', '/pages', ['route' => '/neu-legal', 'title' => 'x', 'template' => 'legal'])['status']);
	assert_same(403, $mod->call('POST', '/pages', ['route' => '/thekenteams/falsch', 'title' => 'x', 'template' => 'event', 'header' => ['start' => '2027-03-06 19:00']])['status']);
	assert_same(403, $mod->call('POST', '/pages', ['route' => '/anfragen/gefaelscht', 'title' => 'x', 'template' => 'request'])['status']);
});

test('Moderation löscht nur unveröffentlichte Termine, keine Anfragen', function () {
	$mod = ApiClient::moderation();
	assert_same(403, $mod->call('DELETE', '/pages/termine/kneipenabend-mit-dem-beispiel-thekenteam')['status']);
	assert_same(204, $mod->call('DELETE', '/pages/termine/api-testtermin')['status']);
	assert_same(403, $mod->call('DELETE', '/pages/impressum')['status']);
});

test('Kopie eines veröffentlichten Termins durch die Moderation ist unveröffentlicht', function () use ($pageHead) {
	$mod  = ApiClient::moderation();
	$copy = $mod->call('POST', '/pages/termine/kneipenabend-mit-dem-beispiel-thekenteam/copy', ['route' => '/termine/kopie-test']);
	assert_true(in_array($copy['status'], [200, 201], true), $copy['body']);

	$header = $pageHead('/termine/kopie-test');
	assert_false($header['published']);
	assert_same('reserviert', $header['orgstatus']);
	assert_contains('Kopie von', $header['changelog'][0]['changes']);
	assert_same(404, (new HttpClient())->get('/termine/kopie-test')['status']);

	// Rechtstext kopieren: nein
	$legal = $mod->call('POST', '/pages/impressum/copy', ['route' => '/termine/impressum-kopie']);
	assert_same(403, $legal['status']);
	assert_same(null, TestServer::pageFile('/termine/impressum-kopie'));

	assert_same(403, $mod->call('POST', '/pages/termine/reorder', ['order' => []])['status'], 'Reihenfolge');
	ApiClient::admin()->call('DELETE', '/pages/termine/kopie-test');
});

test('Administration bestätigt und veröffentlicht; Doppelbelegung blockiert', function () use ($pageHead) {
	$admin = ApiClient::admin();

	$publish = $admin->update('/termine/demo-termin-zur-freigabe', ['published' => true, 'orgstatus' => 'freigabe']);
	assert_same(200, $publish['status'], $publish['body']);
	assert_same('veroeffentlicht', $pageHead('/termine/demo-termin-zur-freigabe')['orgstatus'], 'Status beim Veröffentlichen');
	assert_same(200, (new HttpClient())->get('/termine/demo-termin-zur-freigabe')['status']);

	$conflict = $admin->update('/termine/demo-doppelbelegung', ['published' => true, 'orgstatus' => 'bestaetigt']);
	assert_same(403, $conflict['status']);
	assert_contains('Doppelbelegung', $conflict['body']);

	$accepted = $admin->update('/termine/demo-doppelbelegung', ['published' => true, 'orgstatus' => 'bestaetigt', 'conflictaccepted' => true]);
	assert_same(200, $accepted['status'], $accepted['body']);
});

test('Anfragen: Moderation ändert nur den Bearbeitungsstand', function () use ($pageHead) {
	// Anfrage wie vom Formular gespeichert (das Formular selbst prüft tests/http/form.php;
	// dessen Rate-Limit ist hier schon ausgeschöpft)
	$dir = TestServer::pages() . '/anfragen/anfrage-api-test';
	@mkdir($dir, 0777, true);
	file_put_contents($dir . '/request.md', "---\ntitle: 'Anfrage API-Anfrage'\npublished: false\nroutable: false\nvisible: false\ngroupname: API-Anfrage\ngrouptype: verein\ncontactname: Anna\nemail: api@example.org\nintro: 'Wir sind ein Testverein.'\nsubmittedat: '" . date('Y-m-d H:i:s') . "'\nprocessing: neu\n---\n");

	// direkt geschriebene Dateien erkennt Grav erst nach dem Leeren des Caches
	TestServer::run([TestServer::$dir . '/grav/bin/grav', 'cache']);

	$requests = array_values(array_filter(stored_requests(), fn ($f) => str_contains((string)file_get_contents($f), 'API-Anfrage')));
	assert_same(1, count($requests), 'Anfrage gespeichert');
	$route = '/anfragen/' . basename(dirname($requests[0]));

	$mod = ApiClient::moderation();
	$ok  = $mod->update($route, ['processing' => 'inbearbeitung', 'email' => 'boese@example.org', 'published' => true]);
	assert_same(200, $ok['status'], $ok['body']);

	$header = $pageHead($route);
	assert_same('inbearbeitung', $header['processing']);
	assert_same('api@example.org', $header['email'], 'Formularangaben bleiben unverändert');
	assert_false($header['published'], 'Anfragen sind nie veröffentlicht');
	assert_same(404, (new HttpClient())->get($route)['status']);

	assert_same(403, $mod->call('DELETE', '/pages' . $route)['status']);

	$overview = $mod->call('GET', '/kneipe/overview');
	assert_same(200, $overview['status'], $overview['body']);
	assert_contains('API-Anfrage', $overview['body'], $overview['body']);
	assert_not_contains('api@example.org', $overview['body'], 'keine Kontaktdaten in der Übersicht');
	assert_same([], $overview['json']['data']['warnings'], 'Hinweise nur für die Administration');

	// Ablehnung: nichts wird veröffentlicht, die Anfrage verlässt die offene Liste
	$admin    = ApiClient::admin();
	$rejected = $admin->update($route, ['processing' => 'abgelehnt', 'processingnote' => 'Termin schon vergeben']);
	assert_same(200, $rejected['status'], $rejected['body']);
	assert_false($pageHead($route)['published']);
	assert_same(404, (new HttpClient())->get($route)['status']);
	assert_not_contains('API-Anfrage', $admin->call('GET', '/kneipe/overview')['body']);
	assert_not_contains('API-Anfrage', (new HttpClient())->get('/termine')['body']);
});

test('ohne Freigabemodus darf die Moderation veröffentlichen', function () use ($pageHead) {
	$admin = ApiClient::admin();
	$off   = $admin->update('/einstellungen', ['approvalmode' => false]);
	assert_same(200, $off['status'], $off['body']);

	$mod = ApiClient::moderation();
	$ok  = $mod->update('/termine/demo-reserviert', ['published' => true, 'orgstatus' => 'bestaetigt', 'start' => '2026-12-04 19:00']);
	assert_same(200, $ok['status'], $ok['body']);
	assert_true($pageHead('/termine/demo-reserviert')['published']);

	assert_same(200, $admin->update('/einstellungen', ['approvalmode' => true])['status']);
});
