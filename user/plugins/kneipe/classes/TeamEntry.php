<?php

namespace Grav\Plugin\Kneipe;

use Grav\Common\Page\Interfaces\PageInterface;

/**
 * Ein Thekenteam (Seite mit Template „team“).
 *
 * Öffentlich nur, wenn veröffentlicht UND die Einwilligung zur
 * Veröffentlichung dokumentiert ist (consent). Interne Kontaktdaten
 * (contactperson, contactemail, contactphone, internalnote, consentnote)
 * gibt diese Klasse bewusst nicht aus.
 */
final class TeamEntry
{
    use HeaderAccess;

    public const TYPES = [
        'verein'      => 'Verein',
        'unternehmen' => 'Unternehmen',
        'initiative'  => 'Initiative',
        'privat'      => 'Private Gruppe',
    ];

    public function __construct(
        private PageInterface $page,
        private Service $service,
    ) {
    }

    public function page(): PageInterface
    {
        return $this->page;
    }

    public function id(): string
    {
        return (string)$this->page->route();
    }

    public function url(): string
    {
        return (string)$this->page->url();
    }

    public function title(): string
    {
        return (string)$this->page->title();
    }

    public function isPublished(): bool
    {
        return $this->bool('published', true);
    }

    public function isPublic(): bool
    {
        return $this->isPublished() && $this->bool('consent');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->string('teamtype')] ?? 'Gruppe';
    }

    public function teaser(): string
    {
        return $this->string('teaser');
    }

    public function textHtml(): string
    {
        return Richtext::sanitize((string)$this->page->content());
    }

    public function logo(): ?object
    {
        return $this->service->image($this->page, $this->string('logo'));
    }

    public function cover(): ?object
    {
        return $this->service->image($this->page, $this->string('cover'));
    }

    /** @return object[] */
    public function gallery(): array
    {
        return $this->service->images($this->page, $this->get('gallery'));
    }

    /** @return EventEntry[] alle Termine dieses Teams, auch unveröffentlichte */
    public function events(): array
    {
        return array_values(array_filter(
            $this->service->allEvents(),
            fn (EventEntry $event) => $this->service->normalizeRoute($event->teamRoute()) === $this->id()
        ));
    }

    /** @return EventEntry[] öffentliche kommende Termine */
    public function upcomingEvents(?int $now = null): array
    {
        return $this->service->sortEvents(array_filter(
            $this->events(),
            fn (EventEntry $e) => $e->isPublic() && $e->isFree() === false && $e->isPast($now) === false
        ));
    }

    /** @return EventEntry[] öffentliche vergangene Termine, neueste zuerst */
    public function pastEvents(int $limit = 10, ?int $now = null): array
    {
        return array_slice($this->service->sortEvents(array_filter(
            $this->events(),
            fn (EventEntry $e) => $e->isPublic() && $e->isFree() === false && $e->isPast($now)
        ), true), 0, $limit);
    }

    /** Öffentliche Links (Website und soziale Netzwerke), nur http(s) */
    public function publicLinks(): array
    {
        $links = [];

        if ($this->string('website') !== '') {
            $links[] = ['label' => 'Website', 'url' => $this->string('website')];
        }

        foreach ($this->rows('social') as $row) {
            $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';

            if ($url !== '') {
                $label = is_string($row['platform'] ?? null) && trim($row['platform']) !== '' ? trim($row['platform']) : 'Profil';
                $links[] = ['label' => $label, 'url' => $url];
            }
        }

        return array_values(array_filter(
            $links,
            fn (array $link): bool => preg_match('~^https?://~i', $link['url']) === 1
        ));
    }
}
