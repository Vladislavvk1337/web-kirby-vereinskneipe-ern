<?php

/**
 * Eigene Routen: robots.txt, XML-Sitemap und Sperre für den
 * Anfrage-Bereich (nur im Panel sichtbar).
 */

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Response;

return [
	[
		// Anfragen enthalten personenbezogene Daten und haben keine
		// öffentliche Ansicht – auch nicht für angemeldete Personen
		'pattern' => ['anfragen', 'anfragen/(:all)', 'anfragen.(:any)'],
		'method'  => 'ALL',
		'action'  => fn () => false,
	],
	[
		'pattern' => 'robots.txt',
		'action'  => function () {
			$kirby = App::instance();
			$lines = ['User-agent: *'];

			if (kneipe()->noindex() === true) {
				$lines[] = 'Disallow: /';
			} else {
				$lines[] = 'Disallow: /panel';
				$lines[] = 'Disallow: /termin-anfragen/danke';
				$lines[] = 'Disallow: /bausteine';
				$lines[] = '';
				$lines[] = 'Sitemap: ' . $kirby->url() . '/sitemap.xml';
			}

			return new Response(implode("\n", $lines) . "\n", 'text/plain');
		},
	],
	[
		'pattern' => 'sitemap.xml',
		'action'  => function () {
			$site  = App::instance()->site();
			$pages = [];

			foreach ($site->index() as $page) {
				/** @var Page $page */
				$template = $page->intendedTemplate()->name();

				if (in_array($template, ['error', 'confirmation', 'styleguide', 'requests', 'request'], true) === true) {
					continue;
				}

				if ($template === 'event' && $page->isPublic() === false) {
					continue;
				}

				if ($template === 'team' && $page->isPublic() === false) {
					continue;
				}

				if ($page->noindex()->toBool() === true) {
					continue;
				}

				$pages[] = $page;
			}

			$xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

			foreach ($pages as $page) {
				$xml[] = '  <url>';
				$xml[] = '    <loc>' . htmlspecialchars($page->url(), ENT_XML1) . '</loc>';
				$xml[] = '    <lastmod>' . date('c', $page->modified()) . '</lastmod>';
				$xml[] = '  </url>';
			}

			$xml[] = '</urlset>';

			return new Response(implode("\n", $xml) . "\n", 'application/xml');
		},
	],
];
