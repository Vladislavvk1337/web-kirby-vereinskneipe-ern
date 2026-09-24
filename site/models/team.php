<?php

use Kirby\Cms\Page;
use Kirby\Cms\Pages;

/**
 * Seitenmodell für ein Thekenteam (Template „team“).
 *
 * Öffentlich nur mit dokumentierter Einwilligung zur Veröffentlichung.
 * Interne Kontaktdaten (contactperson, contactemail, contactphone) werden
 * von keinem Template ausgegeben – dieses Modell bietet dafür bewusst
 * keine Hilfsmethoden an.
 */
class TeamPage extends Page
{
	public const TYPES = [
		'verein'      => 'Verein',
		'unternehmen' => 'Unternehmen',
		'initiative'  => 'Initiative',
		'privat'      => 'Private Gruppe',
	];

	public function isPublic(): bool
	{
		return $this->isDraft() === false && $this->content()->get('consent')->toBool() === true;
	}

	public function typeLabel(): string
	{
		return self::TYPES[$this->content()->get('teamtype')->value()] ?? 'Gruppe';
	}

	/** Alle Termine dieses Teams (auch Entwürfe – für das Panel) */
	public function events(): Pages
	{
		return kneipe()->allEvents()->filter(
			fn ($event) => $event->content()->get('team')->toPage()?->id() === $this->id()
		);
	}

	/** Öffentliche kommende Termine */
	public function upcomingEvents(): Pages
	{
		return kneipe()->sortEvents(
			$this->events()->filter(fn ($event) => $event->isPublic() && $event->isFree() === false && $event->isPast() === false)
		);
	}

	/** Öffentliche vergangene Termine, neueste zuerst */
	public function pastEvents(): Pages
	{
		return kneipe()->sortEvents(
			$this->events()->filter(fn ($event) => $event->isPublic() && $event->isFree() === false && $event->isPast() === true),
			true
		);
	}

	/** Für das Panel: kommende Termine inkl. Entwürfe */
	public function panelUpcomingEvents(): Pages
	{
		return kneipe()->sortEvents($this->events()->filter(fn ($event) => $event->isPast() === false));
	}

	public function panelPastEvents(): Pages
	{
		return kneipe()->sortEvents($this->events()->filter(fn ($event) => $event->isPast() === true), true);
	}

	/** Öffentliche Links (Website und soziale Netzwerke) */
	public function publicLinks(): array
	{
		$links = [];

		if ($this->website()->isNotEmpty()) {
			$links[] = ['label' => 'Website', 'url' => $this->website()->value()];
		}

		foreach ($this->social()->toStructure() as $item) {
			if ($item->url()->isNotEmpty()) {
				$links[] = ['label' => $item->platform()->or('Profil')->value(), 'url' => $item->url()->value()];
			}
		}

		return array_values(array_filter(
			$links,
			fn (array $link): bool => preg_match('!^https?://!i', $link['url']) === 1
		));
	}
}
