<?php

return function ($site) {
	$kneipe = kneipe();
	$next   = $kneipe->nextOpening();

	return [
		'next'     => $next,
		'nextTeam' => $next?->publicTeam() ?? $kneipe->upcomingEvents()->filter(fn ($e) => $e->publicTeam() !== null)->first()?->publicTeam(),
		'upcoming' => $kneipe->upcomingEvents(3),
		'free'     => $kneipe->freeEvents(6),
		'article'  => $site->find('aktuelles')?->children()->listed()->sortBy('date', 'desc')->first(),
	];
};
