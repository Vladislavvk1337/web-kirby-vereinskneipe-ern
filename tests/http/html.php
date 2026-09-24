<?php

require_once dirname(__DIR__) . '/support/http.php';

/**
 * HTML-Grundvalidität und Barrierefreiheit (automatisch prüfbarer Teil):
 * Doctype, lang, Titel, Beschreibung, genau eine H1, keine übersprungenen
 * Überschriftenebenen, eindeutige IDs, gültige ARIA-Verweise, Alternativtexte,
 * beschriftete Formularfelder, keine Inline-Skripte/-Styles (CSP).
 */
function check_html(string $path, string $html): array
{
	$problems = [];

	if (!str_starts_with($html, '<!doctype html>')) {
		$problems[] = 'Doctype fehlt';
	}

	$doc = new DOMDocument();
	libxml_use_internal_errors(true);
	$doc->loadHTML($html, LIBXML_NONET);

	foreach (libxml_get_errors() as $error) {
		// libxml kennt HTML5-Elemente nicht (Code 801) – alles andere zählt
		if ($error->code !== 801) {
			$problems[] = 'Parser: ' . trim($error->message) . ' (Zeile ' . $error->line . ')';
		}
	}

	libxml_clear_errors();
	$xpath = new DOMXPath($doc);

	if ($doc->documentElement?->getAttribute('lang') !== 'de') {
		$problems[] = 'lang="de" fehlt';
	}

	if (trim($xpath->evaluate('string(//title)')) === '') {
		$problems[] = 'Titel fehlt';
	}

	if ($xpath->query('//meta[@name="description"][@content!=""]')->length !== 1) {
		$problems[] = 'Meta-Beschreibung fehlt';
	}

	if ($xpath->query('//link[@rel="canonical"]')->length !== 1) {
		$problems[] = 'Canonical fehlt';
	}

	$h1 = $xpath->query('//h1')->length;
	if ($h1 !== 1) {
		$problems[] = "$h1 × H1";
	}

	$last = 0;
	foreach ($xpath->query('//main//h1|//main//h2|//main//h3|//main//h4|//main//h5|//main//h6') as $heading) {
		$level = (int)substr($heading->nodeName, 1);
		if ($last > 0 && $level > $last + 1) {
			$problems[] = "Überschrift springt von h$last auf h$level: " . trim($heading->textContent);
		}
		$last = $level;
	}

	$ids = [];
	foreach ($xpath->query('//*[@id]') as $el) {
		$id = $el->getAttribute('id');
		if (isset($ids[$id])) {
			$problems[] = "doppelte ID $id";
		}
		$ids[$id] = true;
	}

	foreach ($xpath->query('//*[@aria-describedby or @aria-labelledby or @aria-controls]') as $el) {
		foreach (['aria-describedby', 'aria-labelledby', 'aria-controls'] as $attr) {
			foreach (array_filter(explode(' ', $el->getAttribute($attr))) as $ref) {
				if (!isset($ids[$ref])) {
					$problems[] = "$attr verweist auf fehlende ID $ref";
				}
			}
		}
	}

	foreach ($xpath->query('//img[not(@alt)]') as $img) {
		$problems[] = 'Bild ohne alt: ' . $img->getAttribute('src');
	}

	foreach ($xpath->query('//img[not(@width) or not(@height)]') as $img) {
		$problems[] = 'Bild ohne feste Abmessungen: ' . $img->getAttribute('src');
	}

	foreach ($xpath->query('//input[not(@type="hidden")]|//select|//textarea') as $field) {
		$id = $field->getAttribute('id');
		if ($id === '' || $xpath->query('//label[@for="' . $id . '"]')->length === 0) {
			$problems[] = 'Feld ohne Label: ' . ($field->getAttribute('name') ?: $id);
		}
	}

	foreach ($xpath->query('//a[not(@href)]') as $a) {
		$problems[] = 'Link ohne href: ' . trim($a->textContent);
	}

	foreach ($xpath->query('//a[@href]') as $a) {
		if (trim($a->textContent) === '' && $xpath->query('.//img[@alt!=""]', $a)->length === 0 && !$a->getAttribute('aria-label')) {
			$problems[] = 'Link ohne Text: ' . $a->getAttribute('href');
		}
	}

	foreach ($xpath->query('//script[not(@src)][not(@type="application/ld+json")]') as $script) {
		$problems[] = 'Inline-Skript (CSP)';
	}

	foreach ($xpath->query('//*[@style]|//*[@onclick]|//*[@onsubmit]|//*[@onload]') as $el) {
		$problems[] = 'Inline-Style/-Handler an ' . $el->nodeName;
	}

	foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
		if (json_decode($script->textContent) === null) {
			$problems[] = 'ungültiges JSON-LD';
		}
	}

	if ($xpath->query('//a[@class="skip-link"][@href="#inhalt"]')->length !== 1 || !isset($ids['inhalt'])) {
		$problems[] = 'Sprunglink fehlt';
	}

	return array_map(fn ($p) => "$path: $p", $problems);
}

test('HTML-Grundvalidität und Barrierefreiheits-Grundregeln', function () {
	$http = new HttpClient();
	preg_match_all('!<loc>https?://[^/]+([^<]*)</loc>!', $http->get('/sitemap.xml')['body'], $m);
	$paths = array_unique([...$m[1], '/', '/termine?jahr=2026&monat=10', '/termine?zeitraum=vergangen', '/termin-anfragen/danke', '/bausteine', '/gibt-es-nicht']);
	$problems = [];

	foreach ($paths as $path) {
		$problems = [...$problems, ...check_html($path ?: '/', $http->get($path ?: '/')['body'])];
	}

	// Formular im Fehlerzustand
	$hidden = hidden_fields($http->get('/termin-anfragen')['body']);
	$problems = [...$problems, ...check_html('/termin-anfragen (Fehler)', $http->post('/termin-anfragen', $hidden)['body'])];

	assert_same([], $problems, count($problems) . " Probleme\n      " . implode("\n      ", $problems));
});

test('strukturierte Daten: Event für bestätigte und abgesagte Termine', function () {
	$http = new HttpClient();
	$confirmed = $http->get('/termine/kneipenabend-mit-dem-beispiel-thekenteam')['body'];
	assert_contains('"@type":"Event"', $confirmed);
	assert_contains('"eventStatus":"https://schema.org/EventScheduled"', $confirmed);
	assert_contains('"startDate":"2026-10-02T19:00:00+02:00"', $confirmed);
	assert_not_contains('"offers"', $confirmed, 'keine erfundenen Preise');

	assert_contains('EventCancelled', $http->get('/termine/abend-faellt-aus')['body']);
	assert_not_contains('"@type":"Event"', $http->get('/termine/beispieltermin-noch-frei-16-10')['body'], 'freie Termine sind keine Veranstaltung');
	assert_not_contains('"@type":"Event"', $http->get('/termine/private-feier')['body']);
	assert_not_contains('BarOrPub', $http->get('/')['body'], 'ohne vollständige Adresse keine Organisation');
});
