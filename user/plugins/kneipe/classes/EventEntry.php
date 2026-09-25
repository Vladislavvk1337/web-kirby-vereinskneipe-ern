<?php

namespace Grav\Plugin\Kneipe;

use Grav\Common\Page\Interfaces\PageInterface;

/**
 * Ein Termin (Seite mit Template „event“) mit den Regeln für die öffentliche
 * Darstellung.
 *
 * Öffentlich ist ein Termin nur, wenn die Seite veröffentlicht ist
 * (published) und sein organisatorischer Status eine öffentliche
 * Entsprechung hat (EventStatus). Interne Felder (Notiz, verantwortliche
 * Person, Protokoll) gibt diese Klasse nie für die Website aus.
 */
final class EventEntry
{
    use HeaderAccess;

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

    public function slug(): string
    {
        return (string)$this->page->slug();
    }

    public function title(): string
    {
        return (string)$this->page->title();
    }

    /** Stand auf der Festplatte – eine Admin-Vorschau schaltet published nur für die Anfrage frei */
    public function isPublished(): bool
    {
        return $this->bool('published', true);
    }

    // ------------------------------------------------------------------
    // Zeiten
    // ------------------------------------------------------------------

    public function startTimestamp(): ?int
    {
        return $this->timestampFrom('start');
    }

    public function endTimestamp(): int
    {
        return $this->itemFrom()['end'];
    }

    /**
     * Zeitstempel eines Datumsfelds; $data überschreibt den gespeicherten
     * Inhalt (für Prüfungen vor dem Speichern).
     */
    public function timestampFrom(string $field, array $data = []): ?int
    {
        $value = array_key_exists($field, $data) ? $data[$field] : $this->get($field);

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        $time = is_string($value) && trim($value) !== '' ? strtotime($value) : false;

        return $time === false ? null : $time;
    }

    /** Zeitraum und Status als Array für Calendar/Overlap */
    public function itemFrom(array $data = []): array
    {
        $allDay = self::truthy(array_key_exists('allday', $data) ? $data['allday'] : $this->get('allday'));
        $start  = $this->timestampFrom('start', $data);
        $status = array_key_exists('orgstatus', $data) ? (string)$data['orgstatus'] : $this->string('orgstatus');

        return [
            'id'        => $this->id(),
            'start'     => $start ?? 0,
            'end'       => Calendar::effectiveEnd($start ?? 0, $this->timestampFrom('end', $data), $allDay),
            'allday'    => $allDay,
            'orgstatus' => $status !== '' ? $status : 'frei',
        ];
    }

    public function isAllDay(): bool
    {
        return $this->bool('allday');
    }

    public function hasEndTime(): bool
    {
        return $this->string('end') !== '' && $this->isAllDay() === false;
    }

    public function isPast(?int $now = null): bool
    {
        return $this->endTimestamp() <= ($now ?? time());
    }

    /** Beginn des Einlasses als Zeitstempel oder null */
    public function doorsTimestamp(): ?int
    {
        $doors = $this->string('doors');
        $start = $this->startTimestamp();

        if ($doors === '' || $start === null || $this->isAllDay()) {
            return null;
        }

        $time = strtotime(date('Y-m-d', $start) . ' ' . $doors);

        return $time === false ? null : $time;
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    public function orgStatus(): string
    {
        return $this->string('orgstatus') ?: 'frei';
    }

    public function orgStatusLabel(): string
    {
        return EventStatus::orgLabel($this->orgStatus());
    }

    public function eventType(): string
    {
        return $this->string('category') ?: 'kneipenabend';
    }

    public function eventTypeLabel(): string
    {
        return EventStatus::categoryLabel($this->eventType());
    }

    public function publicStatus(): ?string
    {
        return EventStatus::publicKey($this->orgStatus(), $this->eventType());
    }

    public function publicStatusLabel(): ?string
    {
        return EventStatus::publicLabel($this->publicStatus());
    }

    /** Veröffentlicht und mit öffentlichem Status */
    public function isPublic(): bool
    {
        return $this->isPublished() && $this->publicStatus() !== null;
    }

    public function isFree(): bool
    {
        return $this->publicStatus() === 'frei';
    }

    public function isCancelled(): bool
    {
        return $this->publicStatus() === 'abgesagt';
    }

    public function isClosed(): bool
    {
        return $this->publicStatus() === 'geschlossen';
    }

    /** Findet statt und ist für Gäste geöffnet */
    public function isOpening(): bool
    {
        return $this->publicStatus() === 'bestaetigt';
    }

    public function isPrivateEvent(): bool
    {
        return $this->eventType() === 'privat';
    }

    // ------------------------------------------------------------------
    // Öffentliche Angaben
    // ------------------------------------------------------------------

    /**
     * Titel für die Website. Private Veranstaltungen erscheinen ohne
     * Details, damit keine Namen oder Anlässe öffentlich werden.
     */
    public function publicTitle(): string
    {
        if ($this->isPrivateEvent()) {
            return 'Geschlossene Gesellschaft';
        }

        if ($this->isFree() && trim($this->title()) === '') {
            return 'Dieser Termin ist noch frei';
        }

        return $this->title();
    }

    public function publicTeaser(): string
    {
        if ($this->isPrivateEvent()) {
            return 'An diesem Tag ist die Kneipe für eine private Veranstaltung reserviert.';
        }

        return $this->string('teaser');
    }

    /** Freitext-Angaben, die nur bei nicht privaten Terminen erscheinen */
    public function publicField(string $key): string
    {
        if ($this->isPrivateEvent() || in_array($key, ['audience', 'admission', 'publiccontact'], true) === false) {
            return '';
        }

        return $this->string($key);
    }

    /** Zugeordnetes Thekenteam – nur wenn es öffentlich erscheinen darf */
    public function publicTeam(): ?TeamEntry
    {
        if ($this->isPrivateEvent()) {
            return null;
        }

        $team = $this->team();

        return $team !== null && $team->isPublic() ? $team : null;
    }

    /** Zugeordnetes Team, auch unveröffentlicht (für Redaktion und Prüfungen) */
    public function team(): ?TeamEntry
    {
        $route = $this->string('team');

        return $route !== '' ? $this->service->team($route) : null;
    }

    public function teamRoute(): string
    {
        return $this->string('team');
    }

    public function locationName(): string
    {
        return $this->string('location') ?: $this->service->setting('venue');
    }

    public function locationAddress(): string
    {
        return $this->string('address') ?: $this->service->addressLine();
    }

    /** Beschreibung als bereinigtes HTML (Markdown-Inhalt der Seite) */
    public function textHtml(): string
    {
        if ($this->isPrivateEvent()) {
            return '';
        }

        return Richtext::sanitize((string)$this->page->content());
    }

    public function cover(): ?object
    {
        return $this->isPrivateEvent() ? null : $this->service->image($this->page, $this->string('cover'));
    }

    /** @return object[] */
    public function gallery(): array
    {
        return $this->isPrivateEvent() ? [] : $this->service->images($this->page, $this->get('gallery'));
    }

    public function metaTitle(): string
    {
        return $this->string('seotitle') ?: $this->publicTitle();
    }

    public function metaDescription(): string
    {
        return $this->string('seodescription') ?: $this->publicTeaser();
    }

    /** Kalenderdaten für Calendar und Overlap */
    public function toCalendarItem(): array
    {
        return [
            ...$this->itemFrom(),
            'title'    => $this->publicTitle(),
            'category' => $this->eventType(),
            'public'   => $this->publicStatus(),
            'entry'    => $this,
        ];
    }

    // ------------------------------------------------------------------
    // Redaktion
    // ------------------------------------------------------------------

    /**
     * Andere Termine (auch unveröffentlichte), die sich zeitlich
     * überschneiden.
     *
     * @return self[]
     */
    public function conflicts(array $data = []): array
    {
        $candidate = $this->itemFrom($data);

        if ($candidate['start'] === 0) {
            return [];
        }

        $others = [];
        $byId   = [];

        foreach ($this->service->allEvents() as $event) {
            if ($event->startTimestamp() !== null) {
                $others[]              = $event->itemFrom();
                $byId[$event->id()]    = $event;
            }
        }

        return array_values(array_map(
            fn (array $item) => $byId[$item['id']],
            Overlap::conflicts($candidate, $others)
        ));
    }

    public function conflictInfo(array $data = []): string
    {
        $conflicts = $this->conflicts($data);

        if ($conflicts === []) {
            return 'Keine Überschneidung mit anderen Terminen.';
        }

        $list = array_map(
            fn (self $other) => '„' . $other->title() . '“ (' . $this->service->formatDateTime($other) . ', '
                . $other->orgStatusLabel() . ($other->isPublished() ? '' : ', unveröffentlicht') . ')',
            $conflicts
        );

        return 'Überschneidung mit ' . implode('; ', $list) . '.';
    }

    /**
     * Fehlende Angaben für die Redaktions-Übersicht
     *
     * @return string[]
     */
    public function missingInfo(): array
    {
        $missing = [];

        if ($this->startTimestamp() === null) {
            $missing[] = 'Beginn';
        }

        if ($this->isFree() || $this->isClosed() || $this->isCancelled()) {
            return $missing;
        }

        if ($this->string('teaser') === '') {
            $missing[] = 'Kurzbeschreibung';
        }

        if ($this->string('end') === '' && $this->isAllDay() === false) {
            $missing[] = 'Ende';
        }

        if ($this->eventType() === 'thekenteam' && $this->string('team') === '') {
            $missing[] = 'Thekenteam';
        }

        if ($this->string('responsible') === '') {
            $missing[] = 'verantwortliche Person';
        }

        return $missing;
    }

    // ------------------------------------------------------------------
    // iCalendar
    // ------------------------------------------------------------------

    /** Darf als iCalendar-Termin angeboten werden */
    public function hasIcs(): bool
    {
        return $this->isPublic() && in_array($this->publicStatus(), ['bestaetigt', 'abgesagt'], true);
    }

    /** Daten für die iCalendar-Ausgabe (nur öffentliche Angaben) */
    public function icsData(): array
    {
        $team  = $this->publicTeam();
        $doors = $this->doorsTimestamp();
        $lines = array_filter([
            $this->publicTeaser(),
            $team !== null ? 'Thekenteam: ' . $team->title() : null,
            $doors !== null ? 'Einlass: ' . date('H:i', $doors) . ' Uhr' : null,
            $this->publicField('admission') !== '' ? 'Eintritt: ' . $this->publicField('admission') : null,
        ]);

        $changelog = $this->get('changelog');

        return [
            'uid'         => ($this->string('uuid') ?: md5($this->id())) . '@' . $this->service->host(),
            'start'       => $this->startTimestamp(),
            'end'         => $this->endTimestamp(),
            'allday'      => $this->isAllDay(),
            'summary'     => ($this->isCancelled() ? 'Abgesagt: ' : '') . $this->publicTitle(),
            'description' => implode("\n", $lines),
            'location'    => trim($this->locationName() . ', ' . $this->locationAddress(), ', '),
            'url'         => $this->service->absoluteUrl($this->url()),
            'status'      => $this->isCancelled() ? 'CANCELLED' : 'CONFIRMED',
            'modified'    => (int)$this->page->modified(),
            'sequence'    => is_array($changelog) ? count($changelog) : 0,
            'categories'  => $this->eventTypeLabel(),
        ];
    }

    /** Strukturierte Daten (schema.org Event) oder null */
    public function jsonLd(): ?array
    {
        if ($this->isPublic() === false || $this->isPrivateEvent() || in_array($this->publicStatus(), ['bestaetigt', 'abgesagt'], true) === false) {
            return null;
        }

        $start = (int)$this->startTimestamp();
        $data  = [
            '@context'            => 'https://schema.org',
            '@type'               => 'Event',
            'name'                => $this->publicTitle(),
            'startDate'           => $this->isAllDay() ? date('Y-m-d', $start) : date('c', $start),
            'endDate'             => $this->isAllDay() ? date('Y-m-d', $this->endTimestamp() - 1) : date('c', $this->endTimestamp()),
            'eventStatus'         => $this->isCancelled() ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'url'                 => $this->service->absoluteUrl($this->url()),
            'location'            => array_filter([
                '@type'   => 'Place',
                'name'    => $this->locationName() ?: $this->service->name(),
                'address' => $this->locationAddress() ?: null,
            ]),
            'organizer'           => [
                '@type' => 'Organization',
                'name'  => $this->service->name(),
                'url'   => $this->service->absoluteUrl('/'),
            ],
        ];

        if ($this->publicTeaser() !== '') {
            $data['description'] = $this->publicTeaser();
        }

        // Eintritt wird bewusst nicht als „offers“ ausgegeben – keine Preise erfinden
        return $data;
    }
}
