<?php

namespace Kneipe;

use EventPage;
use IntlDateFormatter;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;

/**
 * Zentrale Hilfsfunktionen für Templates, Controller, Hooks und das
 * Panel-Dashboard. Erreichbar über kneipe().
 */
final class Service
{
	private static self|null $instance = null;

	private Pages|null $events = null;

	public function __construct(private App $kirby)
	{
	}

	public static function instance(): self
	{
		$kirby = App::instance();

		if (self::$instance === null || self::$instance->kirby !== $kirby) {
			self::$instance = new self($kirby);
		}

		return self::$instance;
	}

	/** Nach Änderungen an Terminen den Zwischenspeicher leeren */
	public static function flush(): void
	{
		if (self::$instance !== null) {
			self::$instance->events = null;
		}
	}

	public function site(): Site
	{
		return $this->kirby->site();
	}

	// ---------------------------------------------------------------------
	// Termine
	// ---------------------------------------------------------------------

	public function eventsPage(): Page|null
	{
		return $this->site()->find('termine');
	}

	/** Alle Termine inklusive Entwürfe */
	public function allEvents(): Pages
	{
		if ($this->events !== null) {
			return $this->events;
		}

		$page = $this->eventsPage();

		if ($page === null) {
			return $this->events = new Pages([]);
		}

		return $this->events = $page->childrenAndDrafts()->filter(
			fn ($child) => $child instanceof EventPage
		);
	}

	public function sortEvents(Pages $events, bool $descending = false): Pages
	{
		$items = [];

		foreach ($events as $event) {
			$items[] = $event->toCalendarItem();
		}

		return $this->toPages(Calendar::sort($items, $descending));
	}

	/** Öffentlich sichtbare Termine, chronologisch */
	public function publicEvents(): Pages
	{
		return $this->sortEvents(
			$this->allEvents()->filter(fn (EventPage $event) => $event->isPublic() && $event->startTimestamp() !== null)
		);
	}

	/**
	 * Kommende öffentliche Termine.
	 *
	 * @param bool $withFree        freie Termine einbeziehen
	 * @param bool $withClosed      geschlossene/private Tage einbeziehen
	 */
	public function upcomingEvents(int|null $limit = null, bool $withFree = false, bool $withClosed = false, int|null $now = null): Pages
	{
		$now    = $now ?? time();
		$events = $this->publicEvents()->filter(function (EventPage $event) use ($now, $withFree, $withClosed): bool {
			if ($event->isPast($now) === true) {
				return false;
			}

			if ($withFree === false && $event->isFree() === true) {
				return false;
			}

			if ($withClosed === false && $event->isClosed() === true) {
				return false;
			}

			return true;
		});

		return $limit ? $events->limit($limit) : $events;
	}

	/** Vergangene öffentliche Termine für den Rückblick, neueste zuerst */
	public function pastEvents(int|null $limit = null, int|null $now = null): Pages
	{
		$now    = $now ?? time();
		$events = $this->sortEvents(
			$this->publicEvents()->filter(
				fn (EventPage $event) => $event->isPast($now) && $event->isFree() === false && $event->isClosed() === false
			),
			true
		);

		return $limit ? $events->limit($limit) : $events;
	}

	/** Nächster Öffnungstermin: bestätigt, nicht privat oder geschlossen */
	public function nextOpening(int|null $now = null): EventPage|null
	{
		return $this->upcomingEvents(null, false, false, $now)
			->filter(fn (EventPage $event) => $event->isOpening())
			->first();
	}

	public function freeEvents(int|null $limit = null, int|null $now = null): Pages
	{
		$events = $this->upcomingEvents(null, true, false, $now)->filter(fn (EventPage $event) => $event->isFree());
		return $limit ? $events->limit($limit) : $events;
	}

	/**
	 * Termine eines Monats oder – ohne Monat – alle kommenden, gefiltert.
	 */
	public function calendarEvents(int|null $from, int|null $to, string $category = '', string $availability = '', bool $past = false): Pages
	{
		$now   = time();
		$items = [];

		foreach ($this->publicEvents() as $event) {
			$items[] = $event->toCalendarItem();
		}

		if ($from !== null && $to !== null) {
			$items = Calendar::between($items, $from, $to);
			$items = Calendar::sort($items);
		} elseif ($past === true) {
			$items = array_filter(Calendar::past($items, $now), fn (array $item) => $item['public'] !== 'frei');
		} else {
			$items = Calendar::upcoming($items, $now);
		}

		return $this->toPages(Calendar::filter(array_values($items), $category, $availability));
	}

	private function toPages(array $items): Pages
	{
		return new Pages(array_column($items, 'page'));
	}

	// ---------------------------------------------------------------------
	// Dashboard (Panel)
	// ---------------------------------------------------------------------

	public function nextConfirmedEvents(): Pages
	{
		$next = $this->nextOpening();
		return new Pages($next ? [$next] : []);
	}

	public function eventsAwaitingApproval(): Pages
	{
		return $this->sortEvents($this->allEvents()->filter(
			fn (EventPage $event) => $event->orgStatus() === 'freigabe' ||
				($event->isDraft() && in_array($event->orgStatus(), ['bestaetigt', 'veroeffentlicht'], true))
		));
	}

	public function panelFreeEvents(): Pages
	{
		return $this->sortEvents($this->allEvents()->filter(
			fn (EventPage $event) => $event->orgStatus() === 'frei' && $event->isPast() === false
		));
	}

	public function incompleteEvents(): Pages
	{
		return $this->sortEvents($this->allEvents()->filter(
			fn (EventPage $event) => $event->isPast() === false &&
				EventStatus::isActive($event->orgStatus()) &&
				$event->isIncomplete()
		));
	}

	public function conflictingEvents(): Pages
	{
		return $this->sortEvents($this->allEvents()->filter(
			fn (EventPage $event) => $event->isPast() === false && $event->conflicts()->count() > 0
		));
	}

	public function currentTeams(): Pages
	{
		$teams = [];

		foreach ($this->upcomingEvents(null, false, false) as $event) {
			if ($team = $event->content()->get('team')->toPage()) {
				$teams[$team->id()] = $team;
			}
		}

		return new Pages(array_values($teams));
	}

	public function recentlyModified(int $limit = 8): Pages
	{
		$pages = [];

		foreach (['termine', 'thekenteams', 'aktuelles'] as $id) {
			if ($parent = $this->site()->find($id)) {
				foreach ($parent->childrenAndDrafts() as $child) {
					$pages[] = $child;
				}
			}
		}

		usort($pages, fn (Page $a, Page $b) => $b->modified() <=> $a->modified());

		return new Pages(array_slice($pages, 0, $limit));
	}

	public function requestsPage(): Page|null
	{
		return $this->site()->find('anfragen');
	}

	public function openRequests(): Pages
	{
		$page = $this->requestsPage();

		if ($page === null) {
			return new Pages([]);
		}

		return $page->drafts()
			->filter(fn (Page $request) => in_array($request->processing()->or('neu')->value(), ['neu', 'inbearbeitung'], true))
			->sortBy('submittedat', 'asc');
	}

	/** Hinweise für die Administration im Dashboard */
	public function setupWarnings(): array
	{
		$warnings = [];

		if (kneipe_env('KIRBY_CONTENT_SALT') === null || kneipe_env('KIRBY_COOKIE_KEY') === null) {
			$warnings[] = 'KIRBY_CONTENT_SALT oder KIRBY_COOKIE_KEY fehlt – siehe docs/security.md.';
		}

		if ($this->requestRecipient() === null) {
			$warnings[] = 'Keine Empfängeradresse für Terminanfragen gepflegt (Einstellungen).';
		}

		if (kneipe_env('KIRBY_MAIL_TRANSPORT') === 'none') {
			$warnings[] = 'Mailversand ist abgeschaltet (KIRBY_MAIL_TRANSPORT=none). Anfragen werden nur im Panel gespeichert.';
		}

		$missing = $this->missingMasterData();

		if ($missing !== []) {
			$warnings[] = 'Stammdaten fehlen noch: ' . implode(', ', $missing) . '.';
		}

		return $warnings;
	}

	public function setupWarningsText(): string
	{
		$warnings = $this->setupWarnings();
		return $warnings === [] ? 'Alles eingerichtet.' : '• ' . implode("\n• ", $warnings);
	}

	// ---------------------------------------------------------------------
	// Stammdaten und Einstellungen
	// ---------------------------------------------------------------------

	public const MASTER_DATA = [
		'operator'   => 'Betreiber/Träger',
		'street'     => 'Straße und Hausnummer',
		'postalcode' => 'Postleitzahl',
		'city'       => 'Ort',
		'email'      => 'E-Mail-Adresse',
		'phone'      => 'Telefonnummer',
	];

	public function missingMasterData(): array
	{
		$missing = [];

		foreach (self::MASTER_DATA as $field => $label) {
			if ($this->site()->content()->get($field)->isEmpty()) {
				$missing[] = $label;
			}
		}

		return $missing;
	}

	public function approvalMode(): bool
	{
		return $this->site()->content()->get('approvalmode')->toBool(true);
	}

	public function requestRecipient(): string|null
	{
		$email = $this->site()->content()->get('requestrecipient')->or($this->site()->email())->value();
		return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
	}

	public function retentionDays(): int
	{
		$days = $this->site()->content()->get('retentiondays')->toInt();
		return $days > 0 ? min($days, 3650) : 180;
	}

	public function sendReceipt(): bool
	{
		return $this->site()->content()->get('requestreceipt')->toBool(true);
	}

	public function name(): string
	{
		return $this->site()->title()->or('Ehrenamtskneipe Erndtebrück')->value();
	}

	public function addressLine(): string
	{
		$site = $this->site();

		return trim(implode(', ', array_filter([
			$site->street()->value(),
			trim($site->postalcode()->value() . ' ' . $site->city()->value()),
		])));
	}

	public function hasFullAddress(): bool
	{
		$site = $this->site();

		return $site->street()->isNotEmpty() && $site->postalcode()->isNotEmpty() && $site->city()->isNotEmpty();
	}

	/**
	 * Externer Kartenlink (OpenStreetMap) – nur ein Link, keine
	 * eingebettete Karte. Mit Koordinaten punktgenau, sonst Adresssuche.
	 */
	public function mapUrl(): string|null
	{
		$site = $this->site();
		$lat  = str_replace(',', '.', $site->latitude()->value() ?? '');
		$lon  = str_replace(',', '.', $site->longitude()->value() ?? '');

		if (is_numeric($lat) && is_numeric($lon)) {
			return 'https://www.openstreetmap.org/?mlat=' . rawurlencode($lat) . '&mlon=' . rawurlencode($lon) . '#map=18/' . rawurlencode($lat) . '/' . rawurlencode($lon);
		}

		$query = $this->addressLine();

		if ($query === '') {
			return null;
		}

		return 'https://www.openstreetmap.org/search?query=' . rawurlencode($query);
	}

	public function host(): string
	{
		return parse_url($this->site()->url(), PHP_URL_HOST) ?: 'localhost';
	}

	public function secret(): string
	{
		return kneipe_env('KIRBY_CONTENT_SALT') ?? hash('sha256', $this->kirby->root('content') . '|kneipe');
	}

	public function noindex(): bool
	{
		return $this->kirby->option('kneipe.noindex', false) === true;
	}

	// ---------------------------------------------------------------------
	// Datum und Zeit
	// ---------------------------------------------------------------------

	public function formatDate(int $timestamp, string $pattern = 'EEEE, d. MMMM y'): string
	{
		$formatter = new IntlDateFormatter(
			'de_DE',
			IntlDateFormatter::FULL,
			IntlDateFormatter::NONE,
			date_default_timezone_get(),
			IntlDateFormatter::GREGORIAN,
			$pattern
		);

		return $formatter->format($timestamp) ?: date('d.m.Y', $timestamp);
	}

	public function timeRange(EventPage $event): string
	{
		if ($event->isAllDay() === true) {
			return 'ganztägig';
		}

		$start = $event->startTimestamp();

		if ($start === null) {
			return '';
		}

		$text = date('H:i', $start);

		if ($event->hasEndTime() === true) {
			$text .= '–' . date('H:i', $event->endTimestamp());
		}

		return $text . ' Uhr';
	}

	public function formatDateTime(EventPage $event): string
	{
		$start = $event->startTimestamp();

		if ($start === null) {
			return 'ohne Datum';
		}

		return $this->formatDate($start, 'EEE, d. MMM y') . ', ' . $this->timeRange($event);
	}

	public function isoDate(int $timestamp): string
	{
		return date('c', $timestamp);
	}
}
