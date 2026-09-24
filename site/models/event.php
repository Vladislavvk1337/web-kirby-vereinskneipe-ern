<?php

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kneipe\Calendar;
use Kneipe\EventStatus;
use Kneipe\Overlap;

/**
 * Seitenmodell für einen Termin (Template „event“).
 *
 * Öffentlich ist ein Termin nur, wenn er in Kirby veröffentlicht ist und
 * sein organisatorischer Status eine öffentliche Entsprechung hat
 * (siehe Kneipe\EventStatus). Interne Felder (Notiz, Protokoll,
 * verantwortliche Person) gibt dieses Modell nie für die Website aus.
 */
class EventPage extends Page
{
	public function startTimestamp(): int|null
	{
		return $this->timestampFrom('start');
	}

	public function endTimestamp(): int
	{
		return $this->itemFrom()['end'];
	}

	/**
	 * Zeitstempel eines Datumsfelds; $data überschreibt den gespeicherten
	 * Inhalt (für Prüfungen vor dem Speichern)
	 */
	public function timestampFrom(string $field, array $data = []): int|null
	{
		$value = array_key_exists($field, $data) ? $data[$field] : $this->content()->get($field)->value();
		$time  = $value ? strtotime((string)$value) : false;

		return $time === false ? null : $time;
	}

	/**
	 * Zeitraum und Status als Array für Kneipe\Calendar/Overlap
	 */
	public function itemFrom(array $data = []): array
	{
		$get = fn (string $key) => array_key_exists($key, $data) ? (string)$data[$key] : (string)$this->content()->get($key)->value();

		$allDay = in_array(strtolower($get('allday')), ['true', '1'], true);
		$start  = $this->timestampFrom('start', $data);

		return [
			'id'        => $this->id(),
			'start'     => $start ?? 0,
			'end'       => Calendar::effectiveEnd($start ?? 0, $this->timestampFrom('end', $data), $allDay),
			'allday'    => $allDay,
			'orgstatus' => $get('orgstatus') ?: 'frei',
		];
	}

	public function isAllDay(): bool
	{
		return $this->content()->get('allday')->toBool();
	}

	public function hasEndTime(): bool
	{
		return $this->content()->get('end')->isNotEmpty() && $this->isAllDay() === false;
	}

	public function orgStatus(): string
	{
		return $this->content()->get('orgstatus')->or('frei')->value();
	}

	public function eventType(): string
	{
		return $this->content()->get('category')->or('kneipenabend')->value();
	}

	public function eventTypeLabel(): string
	{
		return EventStatus::categoryLabel($this->eventType());
	}

	public function publicStatus(): string|null
	{
		return EventStatus::publicKey($this->orgStatus(), $this->eventType());
	}

	public function publicStatusLabel(): string|null
	{
		return EventStatus::publicLabel($this->publicStatus());
	}

	/** In Kirby veröffentlicht und mit öffentlichem Status */
	public function isPublic(): bool
	{
		return $this->isDraft() === false && $this->publicStatus() !== null;
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

	public function isPast(int|null $now = null): bool
	{
		return $this->endTimestamp() <= ($now ?? time());
	}

	/**
	 * Titel für die Website. Private Veranstaltungen erscheinen ohne
	 * Details, damit keine Namen oder Anlässe öffentlich werden.
	 */
	public function publicTitle(): string
	{
		if ($this->isPrivateEvent() === true) {
			return 'Geschlossene Gesellschaft';
		}

		if ($this->isFree() === true && $this->title()->isEmpty()) {
			return 'Dieser Termin ist noch frei';
		}

		return $this->title()->value();
	}

	public function publicTeaser(): string
	{
		if ($this->isPrivateEvent() === true) {
			return 'An diesem Tag ist die Kneipe für eine private Veranstaltung reserviert.';
		}

		return $this->teaser()->value() ?? '';
	}

	/**
	 * Zugeordnetes Thekenteam – nur wenn es öffentlich erscheinen darf.
	 */
	public function publicTeam(): TeamPage|null
	{
		if ($this->isPrivateEvent() === true) {
			return null;
		}

		$team = $this->content()->get('team')->toPage();

		if ($team instanceof TeamPage && $team->isPublic() === true) {
			return $team;
		}

		return null;
	}

	/** Beginn des Einlasses als Zeitstempel oder null */
	public function doorsTimestamp(): int|null
	{
		$doors = $this->content()->get('doors')->value();
		$start = $this->startTimestamp();

		if (!$doors || $start === null || $this->isAllDay() === true) {
			return null;
		}

		$time = strtotime(date('Y-m-d', $start) . ' ' . $doors);

		return $time === false ? null : $time;
	}

	public function locationName(): string
	{
		return $this->content()->get('location')->or($this->site()->venue())->value() ?? '';
	}

	public function locationAddress(): string
	{
		$address = $this->content()->get('address')->value();

		if ($address) {
			return $address;
		}

		return kneipe()->addressLine();
	}

	/**
	 * Kalenderdaten für Kneipe\Calendar und Kneipe\Overlap
	 */
	public function toCalendarItem(): array
	{
		return [
			...$this->itemFrom(),
			'title'    => $this->publicTitle(),
			'category' => $this->eventType(),
			'public'   => $this->publicStatus(),
			'page'     => $this,
		];
	}

	/**
	 * Andere Termine (auch Entwürfe), die sich zeitlich überschneiden.
	 */
	public function conflicts(array $data = []): Pages
	{
		$candidate = $this->itemFrom($data);

		if ($candidate['start'] === 0) {
			return new Pages([]);
		}

		$others = [];

		foreach (kneipe()->allEvents() as $event) {
			if ($event->startTimestamp() !== null) {
				$others[] = $event->itemFrom();
			}
		}

		$ids = array_column(Overlap::conflicts($candidate, $others), 'id');

		return kneipe()->allEvents()->filter(fn ($event) => in_array($event->id(), $ids, true));
	}

	/** Hinweistext für das Panel (Info-Feld) */
	public function conflictInfo(array $data = []): string
	{
		$conflicts = $this->conflicts($data);

		if ($conflicts->count() === 0) {
			return 'Keine Überschneidung mit anderen Terminen.';
		}

		$list = [];

		foreach ($conflicts as $conflict) {
			$list[] = '„' . $conflict->title() . '“ (' . kneipe()->formatDateTime($conflict) . ', ' .
				EventStatus::orgLabel($conflict->orgStatus()) . ($conflict->isDraft() ? ', Entwurf' : '') . ')';
		}

		$text = '⚠ Überschneidung mit ' . implode('; ', $list) . '.';

		if ($this->content()->get('conflictaccepted')->toBool() === true) {
			$text .= ' Die Überschneidung wurde ausdrücklich zugelassen.';
		} else {
			$text .= ' Veröffentlichen und Bestätigen sind gesperrt, bis die Überschneidung gelöst oder ausdrücklich zugelassen ist.';
		}

		return $text;
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

		if ($this->isFree() === true || $this->isClosed() === true || $this->isCancelled() === true) {
			return $missing;
		}

		if ($this->teaser()->isEmpty()) {
			$missing[] = 'Kurzbeschreibung';
		}

		if ($this->content()->get('end')->isEmpty() && $this->isAllDay() === false) {
			$missing[] = 'Ende';
		}

		if ($this->eventType() === 'thekenteam' && $this->content()->get('team')->isEmpty()) {
			$missing[] = 'Thekenteam';
		}

		if ($this->content()->get('responsible')->isEmpty()) {
			$missing[] = 'verantwortliche Person';
		}

		return $missing;
	}

	public function isIncomplete(): bool
	{
		return $this->missingInfo() !== [];
	}

	public function missingInfoText(): string
	{
		$missing = $this->missingInfo();
		return $missing === [] ? 'Alle wichtigen Angaben sind gepflegt.' : 'Es fehlt noch: ' . implode(', ', $missing) . '.';
	}

	/**
	 * Daten für die iCalendar-Ausgabe (nur öffentliche Angaben)
	 */
	public function icsData(): array
	{
		$lines = array_filter([
			$this->publicTeaser(),
			$this->publicTeam() ? 'Thekenteam: ' . $this->publicTeam()->title() : null,
			$this->doorsTimestamp() ? 'Einlass: ' . date('H:i', $this->doorsTimestamp()) . ' Uhr' : null,
			$this->admission()->isNotEmpty() ? 'Eintritt: ' . $this->admission() : null,
		]);

		$location = trim($this->locationName() . ', ' . $this->locationAddress(), ', ');

		return [
			'uid'         => $this->content()->get('uuid')->or($this->id())->value() . '@' . kneipe()->host(),
			'start'       => $this->startTimestamp(),
			'end'         => $this->endTimestamp(),
			'allday'      => $this->isAllDay(),
			'summary'     => ($this->isCancelled() ? 'Abgesagt: ' : '') . $this->publicTitle(),
			'description' => implode("\n", $lines),
			'location'    => $location,
			'url'         => $this->url(),
			'status'      => $this->isCancelled() ? 'CANCELLED' : 'CONFIRMED',
			'modified'    => $this->modified(),
			'sequence'    => count($this->content()->get('changelog')->yaml()),
			'categories'  => $this->eventTypeLabel(),
		];
	}

	/** Darf als iCalendar-Termin angeboten werden */
	public function hasIcs(): bool
	{
		return $this->isPublic() === true && in_array($this->publicStatus(), ['bestaetigt', 'abgesagt'], true);
	}
}
