<?php

use Kirby\Exception\NotFoundException;

return function ($page, $kirby) {
	// Ohne Einwilligung erscheint ein Team nicht öffentlich
	$preview = $page->isPublic() === false;

	if ($preview === true && $kirby->user() === null) {
		throw new NotFoundException();
	}

	return [
		'preview'  => $preview,
		'noindex'  => $preview,
		'upcoming' => $page->upcomingEvents(),
		'past'     => $page->pastEvents()->limit(10),
	];
};
