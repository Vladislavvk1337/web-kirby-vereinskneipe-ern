<?php

use Kneipe\Ics;

$event = [
	'uid'         => 'abc123@example.org',
	'start'       => strtotime('2026-10-02 19:00'),
	'end'         => strtotime('2026-10-02 23:30'),
	'allday'      => false,
	'summary'     => 'Kneipenabend; mit Team, Musik & mehr',
	'description' => "Zeile eins\nZeile zwei mit Backslash \\ und Umlauten äöüß – " . str_repeat('lang ', 30),
	'location'    => 'Erndtebrück',
	'url'         => 'https://example.org/termine/kneipenabend',
	'status'      => 'CONFIRMED',
	'modified'    => strtotime('2026-09-24 10:00'),
	'sequence'    => 2,
];

test('Kalender hat gültige Struktur mit CRLF und Pflichtfeldern', function () use ($event) {
	$ics = Ics::calendar([$event], 'Test', '-//test//DE', strtotime('2026-09-24 12:00'));

	assert_true(str_starts_with($ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"));
	assert_true(str_ends_with($ics, "END:VCALENDAR\r\n"));
	assert_same(0, preg_match("/(?<!\r)\n/", $ics), 'nur CRLF-Zeilenenden');

	foreach (['PRODID:', 'BEGIN:VEVENT', 'UID:abc123@example.org', 'DTSTAMP:20260924T100000Z', 'DTSTART:20261002T170000Z', 'DTEND:20261002T213000Z', 'STATUS:CONFIRMED', 'SEQUENCE:2', 'END:VEVENT'] as $needle) {
		assert_contains($needle, $ics);
	}
});

test('Text wird nach RFC 5545 maskiert', function () {
	assert_same('a\;b\\,c\\\\d\\ne', Ics::text("a;b,c\\d\ne"));
});

test('lange Zeilen werden nach 75 Oktetten gefaltet, ohne UTF-8 zu zerteilen', function () use ($event) {
	$ics = Ics::calendar([$event], 'Test', '-//test//DE');

	foreach (explode("\r\n", $ics) as $line) {
		assert_true(strlen($line) <= 75, 'Zeile zu lang: ' . strlen($line));
		assert_true(mb_check_encoding($line, 'UTF-8'), 'UTF-8 zerteilt');
	}

	// Entfalten ergibt den ursprünglichen Text
	$unfolded = str_replace("\r\n ", '', $ics);
	assert_contains('DESCRIPTION:Zeile eins\nZeile zwei mit Backslash \\\\ und Umlauten äöüß', $unfolded);
});

test('ganztägige Termine verwenden VALUE=DATE', function () {
	$ics = Ics::calendar([[
		'uid' => 'x', 'start' => strtotime('2026-12-24 00:00'), 'end' => strtotime('2026-12-25 00:00'),
		'allday' => true, 'summary' => 'Geschlossen', 'status' => 'CONFIRMED',
	]], 'Test', '-//test//DE');

	assert_contains('DTSTART;VALUE=DATE:20261224', $ics);
	assert_contains('DTEND;VALUE=DATE:20261225', $ics);
});

test('abgesagte Termine tragen STATUS:CANCELLED', function () use ($event) {
	assert_contains('STATUS:CANCELLED', Ics::calendar([[...$event, 'status' => 'CANCELLED']], 'T', '-//t//DE'));
});
