<?php

/**
 * Migration Kirby → Grav (tools/migrate-kirby-to-grav.php) und Hilfen, die
 * ohne Grav laufen.
 */

use Grav\Plugin\Kneipe\Guard;
use Grav\Plugin\Kneipe\Service;

require_once dirname(__DIR__, 2) . '/tools/migrate-kirby-to-grav.php';

test('Kirby-Inhaltsdatei wird in Felder zerlegt', function () {
	$file = sys_get_temp_dir() . '/kirby-' . bin2hex(random_bytes(4)) . '.txt';
	file_put_contents($file, "Title: Termin\n\n----\n\nText: <p>Zeile</p>\n\n----\n\nOpeninghours:\n\n- \n  days: Mo\n\n----\n\nUuid: abc\n");
	$fields = KirbyToGrav::parseKirbyFile($file);
	unlink($file);

	assert_same('Termin', $fields['title']);
	assert_same('<p>Zeile</p>', $fields['text']);
	assert_contains('days: Mo', $fields['openinghours']);
	assert_same('abc', $fields['uuid']);
});

test('Writer-HTML wird zu Markdown ohne HTML', function () {
	$md = KirbyToGrav::htmlToMarkdown('<p>Das ist <strong>fett</strong> und <em>kursiv</em>, mit <a href="https://example.org">Link</a>.</p><h2>Titel</h2><ul><li>eins</li><li>zwei</li></ul><p>Sternchen * und <script>alert(1)</script></p>');

	assert_contains('Das ist **fett** und *kursiv*, mit [Link](https://example.org).', $md);
	assert_contains("## Titel", $md);
	assert_contains("- eins\n- zwei", $md);
	assert_contains('Sternchen \*', $md, 'Markdown-Zeichen maskiert');
	assert_not_contains('<script>', $md);
	assert_same('schlicht', KirbyToGrav::htmlToMarkdown('schlicht'));
});

test('Frontmatter wird direkt von der Festplatte gelesen', function () {
	if (!load_grav_vendor()) {
		fail('symfony/yaml fehlt – zuerst scripts/dev-server.sh --prepare-only');
	}

	$header = Guard::parseFrontmatter("---\ntitle: 'A'\npublished: false\nchangelog:\n  - { date: x }\n---\nText");
	assert_same('A', $header['title']);
	assert_false($header['published']);
	assert_same('x', $header['changelog'][0]['date']);
	assert_same([], Guard::parseFrontmatter("kein Frontmatter"));
	assert_same([], Guard::parseFrontmatter("---\n: kaputt: [\n---\n"));
});

test('Datumsformat ohne intl-Erweiterung', function () {
	$ts = strtotime('2026-03-05 19:00');
	assert_same('Donnerstag, 5. März 2026', Service::formatDateFallback($ts, 'EEEE, d. MMMM y'));
	assert_same('Do., 5. März 2026', Service::formatDateFallback($ts, 'EEE, d. MMM y'));
	assert_same('Okt.', Service::formatDateFallback(strtotime('2026-10-02'), 'MMM'));
});

test('Platzhalter werden markiert, Text wird maskiert', function () {
	assert_same("&lt;b&gt;x&lt;/b&gt;<br>\ny", Service::text("<b>x</b>\ny"));
	assert_same('<mark class="placeholder">[Platzhalter: Uhrzeit]</mark>', Service::text('[Platzhalter: Uhrzeit]'));
});
