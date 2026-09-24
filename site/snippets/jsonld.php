<?php
/**
 * Strukturierte Daten (schema.org). Organisation bzw. Kneipe nur, wenn
 * Name, Anschrift und Ort gepflegt sind – nichts wird erfunden.
 *
 * @var array $items zusätzliche Objekte (z. B. Event)
 */
$items = $items ?? [];

if ($page->isHomePage() && kneipe()->hasFullAddress()) {
	$org = [
		'@context' => 'https://schema.org',
		'@type'    => 'BarOrPub',
		'name'     => kneipe()->name(),
		'url'      => $site->url(),
		'address'  => [
			'@type'           => 'PostalAddress',
			'streetAddress'   => $site->street()->value(),
			'postalCode'      => $site->postalcode()->value(),
			'addressLocality' => $site->city()->value(),
			'addressCountry'  => 'DE',
		],
	];

	if ($site->email()->isNotEmpty()) {
		$org['email'] = $site->email()->value();
	}

	if ($site->phone()->isNotEmpty()) {
		$org['telephone'] = $site->phone()->value();
	}

	$lat = str_replace(',', '.', (string)$site->latitude()->value());
	$lon = str_replace(',', '.', (string)$site->longitude()->value());

	if (is_numeric($lat) && is_numeric($lon)) {
		$org['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float)$lat, 'longitude' => (float)$lon];
	}

	$items[] = $org;
}

foreach ($items as $item): ?>
  <script type="application/ld+json"><?= json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endforeach;
