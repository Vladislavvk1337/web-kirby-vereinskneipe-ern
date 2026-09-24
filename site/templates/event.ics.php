<?php
/**
 * Einzelner Termin als iCalendar-Datei: /termine/<termin>.ics
 *
 * @var EventPage $page
 */
use Kirby\Exception\NotFoundException;
use Kneipe\Ics;

if ($page->hasIcs() === false) {
	throw new NotFoundException();
}

$kirby->response()->header('Content-Disposition', 'attachment; filename="' . $page->slug() . '.ics"');

echo Ics::calendar([$page->icsData()], kneipe()->name(), '-//' . kneipe()->host() . '//Termine//DE');
