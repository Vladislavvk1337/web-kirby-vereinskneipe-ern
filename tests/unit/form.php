<?php

use Kneipe\FormTimer;
use Kneipe\RateLimiter;
use Kneipe\RequestValidator;
use Kneipe\Richtext;
use Kneipe\Changelog;

$valid = [
	'slot'        => 'termin-16-10',
	'groupname'   => '  Beispielverein   Erndtebrück ',
	'grouptype'   => 'verein',
	'contactname' => 'Demo Person',
	'email'       => 'Demo@Example.org',
	'phone'       => '+49 (0) 1234 / 5678',
	'intro'       => "Wir sind ein kleiner Verein.\r\n\r\n\r\n\r\nUnd wir helfen gern.",
	'message'     => '',
	'privacy'     => 'ja',
];
$slots = ['termin-16-10' => 'Fr., 16. Okt. 2026'];

test('gültige Anfrage wird angenommen und normalisiert', function () use ($valid, $slots) {
	$result = RequestValidator::validate($valid, $slots, '2026-09-24');
	assert_same([], $result['errors']);
	assert_same('Beispielverein Erndtebrück', $result['values']['groupname']);
	assert_same('demo@example.org', $result['values']['email']);
	assert_same("Wir sind ein kleiner Verein.\n\nUnd wir helfen gern.", $result['values']['intro']);
});

test('Pflichtfelder melden verständliche Fehler am jeweiligen Feld', function () use ($slots) {
	$result = RequestValidator::validate([], $slots, '2026-09-24');
	foreach (['slot', 'groupname', 'grouptype', 'contactname', 'email', 'intro', 'privacy'] as $field) {
		assert_true(isset($result['errors'][$field]), "Fehler für $field fehlt");
	}
	assert_false(isset($result['errors']['phone']), 'Telefon ist freiwillig');
	assert_false(isset($result['errors']['message']), 'Nachricht ist freiwillig');
});

test('ungültige Werte werden abgelehnt', function () use ($valid, $slots) {
	$cases = [
		'email'     => ['email' => 'kein-at-zeichen'],
		'phone'     => ['phone' => 'ruf mich an'],
		'grouptype' => ['grouptype' => 'sekte'],
		'slot'      => ['slot' => 'nicht-mehr-frei'],
		'altdate'   => ['altdate' => '2020-01-01'],
		'privacy'   => ['privacy' => 'nein'],
	];

	foreach ($cases as $field => $change) {
		$result = RequestValidator::validate([...$valid, ...$change], $slots, '2026-09-24');
		assert_true(isset($result['errors'][$field]), "$field hätte abgelehnt werden müssen");
	}
});

test('eigenes Wunschdatum statt freiem Termin', function () use ($valid, $slots) {
	$ok = RequestValidator::validate([...$valid, 'slot' => 'anderer', 'wishdate' => '15.11.2026'], $slots, '2026-09-24');
	assert_same([], $ok['errors']);
	assert_same('2026-11-15', $ok['values']['wishdate'], 'TT.MM.JJJJ wird umgewandelt');

	$missing = RequestValidator::validate([...$valid, 'slot' => 'anderer', 'wishdate' => ''], $slots, '2026-09-24');
	assert_true(isset($missing['errors']['wishdate']));

	$past = RequestValidator::validate([...$valid, 'slot' => 'anderer', 'wishdate' => '2026-09-01'], $slots, '2026-09-24');
	assert_true(isset($past['errors']['wishdate']));

	$far = RequestValidator::validate([...$valid, 'slot' => 'anderer', 'wishdate' => '2030-01-01'], $slots, '2026-09-24');
	assert_true(isset($far['errors']['wishdate']));
});

test('Steuerzeichen, unsichtbare Zeichen und Arrays werden entfernt', function () use ($valid, $slots) {
	$result = RequestValidator::validate([...$valid, 'groupname' => "Ver\x00ein\u{202E}", 'message' => ['array']], $slots, '2026-09-24');
	assert_same('Verein', $result['values']['groupname']);
	assert_same('', $result['values']['message']);
});

test('Zeitfalle: signierter Zeitstempel', function () {
	$token = FormTimer::issue('geheim', 1000);
	assert_same(5, FormTimer::elapsed($token, 'geheim', 1005));
	assert_same(null, FormTimer::elapsed($token, 'anderes-geheimnis', 1005), 'falsche Signatur');
	assert_same(null, FormTimer::elapsed('999.manipuliert', 'geheim', 1005));
	assert_same(null, FormTimer::elapsed($token, 'geheim', 1000 + 90000), 'abgelaufen');
});

test('Rate-Limit sperrt nach Grenze und speichert keine IP im Klartext', function () {
	$dir = sys_get_temp_dir() . '/kneipe-rl-' . bin2hex(random_bytes(4));
	$limiter = new RateLimiter($dir, 'geheim', 2, 3600);
	assert_true($limiter->hit('203.0.113.9', 1000));
	assert_true($limiter->hit('203.0.113.9', 1001));
	assert_false($limiter->hit('203.0.113.9', 1002), 'dritter Versuch gesperrt');
	assert_true($limiter->hit('198.51.100.1', 1002), 'andere IP nicht betroffen');
	assert_true($limiter->hit('203.0.113.9', 5000), 'nach Ablauf wieder erlaubt');

	foreach (glob($dir . '/*') as $file) {
		assert_not_contains('203.0.113.9', basename($file) . file_get_contents($file));
	}

	remove_dir($dir);
});

test('Richtext lässt nur sichere Elemente und Links zu', function () {
	$html = Richtext::sanitize('<h1>Titel</h1><p onclick="x()">Text <a href="javascript:alert(1)">böse</a> <a href="https://example.org" target="_blank">gut</a></p><script>alert(1)</script><iframe src="https://example.org"></iframe><img src=x onerror=alert(1)>');
	assert_same('<h2>Titel</h2><p>Text böse <a href="https://example.org" rel="noopener">gut</a></p>', $html);
	assert_same('<p><a href="/termine">intern</a> <a href="mailto:a@example.org">Mail</a> //evil.example</p>', Richtext::sanitize('<p><a href="/termine">intern</a> <a href="mailto:a@example.org">Mail</a> <a href="//evil.example">//evil.example</a></p>'));
});

test('Änderungsprotokoll erkennt geänderte Felder', function () {
	$diff = Changelog::changedFields(
		['Title' => 'A', 'Start' => '2026-10-02 19:00:00', 'Modifiedat' => 'x', 'Text' => ''],
		['title' => 'A', 'start' => '2026-10-02 20:00:00', 'modifiedat' => 'y', 'text' => '', 'team' => '- page://x']
	);
	assert_same(['start', 'team'], $diff);
	assert_same(100, count(Changelog::prepend(array_fill(0, 100, ['x']), ['neu'])));
});
