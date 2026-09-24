<?php
/**
 * Abonnierbarer iCalendar-Feed: /termine.ics
 * Enthält veröffentlichte, bestätigte und abgesagte Termine (letzte
 * 90 Tage und alle kommenden). Freie Termine und geschlossene Tage nicht.
 */
use Kneipe\Ics;

$since  = time() - 90 * 86400;
$events = [];

foreach (kneipe()->publicEvents() as $event) {
	if ($event->hasIcs() && $event->endTimestamp() >= $since) {
		$events[] = $event->icsData();
	}
}

$kirby->response()->header('Cache-Control', 'public, max-age=900');
$kirby->response()->header('Content-Disposition', 'inline; filename="termine.ics"');

echo Ics::calendar($events, kneipe()->name(), '-//' . kneipe()->host() . '//Termine//DE');
