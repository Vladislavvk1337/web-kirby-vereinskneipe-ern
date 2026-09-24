<?php

use Kirby\Cms\Page;
use Kirby\Cms\Pages;

/**
 * Terminübersicht (Template „events“) – Abfragen für das Panel.
 */
class EventsPage extends Page
{
	public function panelUpcoming(): Pages
	{
		return kneipe()->sortEvents(
			kneipe()->allEvents()->filter(fn ($event) => $event->isDraft() === false && $event->isPast() === false)
		);
	}

	public function panelPast(): Pages
	{
		return kneipe()->sortEvents(
			kneipe()->allEvents()->filter(fn ($event) => $event->isDraft() === false && $event->isPast() === true),
			true
		);
	}
}
