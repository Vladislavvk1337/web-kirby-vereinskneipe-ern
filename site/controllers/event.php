<?php

use Kirby\Exception\NotFoundException;

return function ($page, $kirby, $site) {
	// Nicht öffentliche Termine (Entwurf, intern) gibt es für Gäste nicht.
	// Angemeldete Redakteure sehen eine gekennzeichnete Vorschau.
	$preview = $page->isPublic() === false;

	if ($preview === true && $kirby->user() === null) {
		throw new NotFoundException();
	}

	$jsonld = [];

	if ($page->isPublic() && in_array($page->publicStatus(), ['bestaetigt', 'abgesagt'], true) && $page->isPrivateEvent() === false) {
		$start = $page->startTimestamp();
		$event = [
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => $page->publicTitle(),
			'startDate'           => $page->isAllDay() ? date('Y-m-d', $start) : date('c', $start),
			'endDate'             => $page->isAllDay() ? date('Y-m-d', $page->endTimestamp() - 1) : date('c', $page->endTimestamp()),
			'eventStatus'         => $page->isCancelled() ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'url'                 => $page->url(),
			'location'            => array_filter([
				'@type'   => 'Place',
				'name'    => $page->locationName() ?: kneipe()->name(),
				'address' => $page->locationAddress() ?: null,
			]),
			'organizer'           => [
				'@type' => 'Organization',
				'name'  => kneipe()->name(),
				'url'   => $site->url(),
			],
		];

		if ($page->publicTeaser() !== '') {
			$event['description'] = $page->publicTeaser();
		}

		if ($cover = $page->cover()->toFile()) {
			$event['image'] = $cover->thumb(['width' => 1200])->url();
		}

		// Eintritt wird bewusst nicht als „offers“ ausgegeben – keine Preise erfinden
		$jsonld[] = $event;
	}

	return [
		'preview' => $preview,
		'team'    => $page->publicTeam(),
		'jsonld'  => $jsonld,
		'metaTitle' => $page->seotitle()->or($page->publicTitle())->value(),
		'metaDescription' => $page->seodescription()->or($page->publicTeaser())->value(),
		'noindex' => $preview,
	];
};
