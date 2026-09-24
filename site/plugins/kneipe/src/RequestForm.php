<?php

namespace Kneipe;

use EventPage;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Throwable;

/**
 * Ablauf des Formulars „Freien Termin anfragen“.
 *
 * Schutz: CSRF-Token (Kirby-Sitzung), Honeypot, Zeitfalle, Herkunfts-
 * prüfung und Rate-Limit je IP-Hash. Erfolgreiche Anfragen werden als
 * unveröffentlichte Seite unter „anfragen“ gespeichert (nie öffentlich)
 * und per E-Mail gemeldet. Danach Post/Redirect/Get auf die
 * Bestätigungsseite. Formularinhalte gelangen weder in URLs noch in Logs.
 */
final class RequestForm
{
	public const HONEYPOT = 'website';
	public const TIMER    = 'formstart';

	public function __construct(private App $kirby)
	{
	}

	/**
	 * Freie Termine für die Auswahl: Slug => Beschriftung
	 */
	public function slots(): array
	{
		$slots = [];

		foreach (kneipe()->freeEvents() as $event) {
			/** @var EventPage $event */
			$slots[$event->slug()] = kneipe()->formatDateTime($event);
		}

		return $slots;
	}

	/**
	 * Verarbeitet einen POST. Rückgabe:
	 *   ['status' => 'ok'|'spam'|'invalid'|'csrf'|'ratelimit'|'origin', 'values' => [], 'errors' => []]
	 */
	public function handle(array $input, string|null $csrfToken, string|null $origin, string $clientIp): array
	{
		$values = array_map(
			fn ($value) => is_string($value) ? mb_substr($value, 0, 3000) : '',
			array_intersect_key($input, RequestValidator::FIELDS)
		);

		if ($this->originAllowed($origin) === false) {
			return ['status' => 'origin', 'values' => $values, 'errors' => []];
		}

		if (csrf($csrfToken ?? '') !== true) {
			return ['status' => 'csrf', 'values' => $values, 'errors' => []];
		}

		// Honeypot: für Menschen unsichtbar, Bots füllen es aus
		if (trim((string)($input[self::HONEYPOT] ?? '')) !== '') {
			return ['status' => 'spam', 'values' => [], 'errors' => []];
		}

		$elapsed = FormTimer::elapsed((string)($input[self::TIMER] ?? ''), kneipe()->secret());
		$min     = (int)$this->kirby->option('kneipe.requests.minSeconds', 3);

		if ($elapsed === null || $elapsed < $min) {
			return ['status' => 'spam', 'values' => [], 'errors' => []];
		}

		$result = RequestValidator::validate($input, $this->slots(), date('Y-m-d'));

		if ($result['errors'] !== []) {
			return ['status' => 'invalid', 'values' => $result['values'], 'errors' => $result['errors']];
		}

		// Gezählt werden nur vollständige Anfragen – Tippfehler sperren niemanden aus
		$limiter = new RateLimiter(
			$this->kirby->root('cache') . '/kneipe-ratelimit',
			kneipe()->secret(),
			(int)$this->kirby->option('kneipe.requests.rateLimit', 5),
			3600
		);

		if ($limiter->hit($clientIp) === false) {
			return ['status' => 'ratelimit', 'values' => $result['values'], 'errors' => []];
		}

		$request = $this->store($result['values']);
		$this->notify($request, $result['values']);

		// Gelegenheit nutzen, abgelaufene Anfragen und Zähler zu entfernen
		Retention::cleanup($this->kirby, kneipe()->retentionDays());
		$limiter->prune();

		return ['status' => 'ok', 'values' => [], 'errors' => []];
	}

	/**
	 * Herkunft prüfen, wenn der Browser sie mitschickt (Origin-Header).
	 */
	public function originAllowed(string|null $origin): bool
	{
		if ($origin === null || $origin === '' || $origin === 'null') {
			return true;
		}

		$expected = parse_url($this->kirby->url(), PHP_URL_HOST);
		$actual   = parse_url($origin, PHP_URL_HOST);

		return $expected !== null && $expected === $actual;
	}

	public function store(array $values): Page
	{
		$parent = kneipe()->requestsPage();

		if ($parent === null) {
			throw new \RuntimeException('Seite „anfragen“ fehlt.');
		}

		$slotLabel = $values['slot'] === RequestValidator::OTHER
			? 'Eigener Vorschlag: ' . date('d.m.Y', strtotime($values['wishdate']))
			: ($this->slots()[$values['slot']] ?? $values['slot']);

		$event = $values['slot'] !== RequestValidator::OTHER
			? kneipe()->eventsPage()?->find($values['slot'])
			: null;

		return $this->kirby->impersonate('kirby', fn () => $parent->createChild([
			'slug'     => 'anfrage-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)),
			'template' => 'request',
			'draft'    => true,
			'content'  => [
				'title'       => 'Anfrage ' . $values['groupname'] . ' – ' . $slotLabel,
				'slotlabel'   => $slotLabel,
				'slotpage'    => $event ? [$event->uuid()?->toString() ?? $event->id()] : [],
				'wishdate'    => $values['wishdate'],
				'altdate'     => $values['altdate'],
				'groupname'   => $values['groupname'],
				'grouptype'   => $values['grouptype'],
				'contactname' => $values['contactname'],
				'email'       => $values['email'],
				'phone'       => $values['phone'],
				'intro'       => $values['intro'],
				'message'     => $values['message'],
				'privacy'     => 'Zugestimmt am ' . date('d.m.Y \u\m H:i') . ' Uhr',
				'submittedat' => date('Y-m-d H:i:s'),
				'processing'  => 'neu',
			],
		]));
	}

	/**
	 * Benachrichtigung an die Redaktion und optionale Eingangsbestätigung.
	 * Fehler beim Versand verhindern die Speicherung nicht; protokolliert
	 * wird nur die technische Ursache ohne Formularinhalte.
	 */
	public function notify(Page $request, array $values): void
	{
		$from     = $this->kirby->option('kneipe.from') ?? 'noreply@' . kneipe()->host();
		$fromName = $this->kirby->option('kneipe.fromName') ?? kneipe()->name();

		if ($recipient = kneipe()->requestRecipient()) {
			try {
				$this->kirby->email([
					'from'     => $from,
					'fromName' => $fromName,
					'replyTo'  => $values['email'],
					'to'       => $recipient,
					'subject'  => 'Neue Terminanfrage: ' . mb_substr($values['groupname'], 0, 60),
					'template' => 'request-notification',
					'data'     => [
						'request'  => $request,
						'values'   => $values,
						'panelUrl' => $request->panel()->url(),
						'siteName' => kneipe()->name(),
					],
				]);
			} catch (Throwable $e) {
				error_log('[kneipe] Benachrichtigung zur Terminanfrage ' . $request->slug() . ' nicht versendet: ' . $e::class);
			}
		}

		if (kneipe()->sendReceipt() === true) {
			try {
				$this->kirby->email([
					'from'     => $from,
					'fromName' => $fromName,
					'to'       => $values['email'],
					'subject'  => 'Eure Terminanfrage bei ' . kneipe()->name(),
					'template' => 'request-receipt',
					'data'     => [
						'siteName'  => kneipe()->name(),
						'siteUrl'   => $this->kirby->url(),
						'retention' => kneipe()->retentionDays(),
					],
				]);
			} catch (Throwable $e) {
				error_log('[kneipe] Eingangsbestätigung zur Terminanfrage ' . $request->slug() . ' nicht versendet: ' . $e::class);
			}
		}
	}
}
