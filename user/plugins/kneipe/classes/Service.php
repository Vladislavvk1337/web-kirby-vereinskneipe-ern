<?php

namespace Grav\Plugin\Kneipe;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Common\Security;
use Grav\Common\Utils;
use IntlDateFormatter;

/**
 * Zentrale Hilfsfunktionen für Theme, Formular, Freigabe-Prüfung und
 * Admin-Dashboard. In Twig als `kneipe` verfügbar.
 */
final class Service
{
    public const EVENTS_ROUTE   = '/termine';
    public const TEAMS_ROUTE    = '/thekenteams';
    public const NEWS_ROUTE     = '/aktuelles';
    public const REQUESTS_ROUTE = '/anfragen';
    public const SETTINGS_ROUTE = '/einstellungen';
    public const FORM_ROUTE     = '/termin-anfragen';

    /** Stammdaten, die für Impressum und Kontakt gebraucht werden */
    public const MASTER_DATA = [
        'operator'   => 'Betreiber/Träger',
        'street'     => 'Straße und Hausnummer',
        'postalcode' => 'Postleitzahl',
        'city'       => 'Ort',
        'email'      => 'E-Mail-Adresse',
        'phone'      => 'Telefonnummer',
    ];

    private static ?self $instance = null;

    /** @var EventEntry[]|null */
    private ?array $events = null;

    /** @var array<string, TeamEntry|null> */
    private array $teams = [];

    private ?array $settings = null;

    public function __construct(private Grav $grav)
    {
    }

    public static function instance(?Grav $grav = null): self
    {
        $grav = $grav ?? Grav::instance();

        if (self::$instance === null || self::$instance->grav !== $grav) {
            self::$instance = new self($grav);
        }

        return self::$instance;
    }

    /** Zwischenspeicher nach Änderungen leeren */
    public function flush(): void
    {
        $this->events   = null;
        $this->teams    = [];
        $this->settings = null;
    }

    public function grav(): Grav
    {
        return $this->grav;
    }

    /** Seitenbaum – im API-Kontext schaltet Grav ihn erst bei Bedarf ein */
    public function pages(): Pages
    {
        $pages = $this->grav['pages'];

        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }

        return $pages;
    }

    /** Seite per Route, auch unveröffentlicht oder nicht routbar */
    public function page(string $route): ?PageInterface
    {
        $page = $this->pages()->find($this->normalizeRoute($route), true);

        return $page instanceof PageInterface && $page->exists() ? $page : null;
    }

    public function normalizeRoute(string $route): string
    {
        $route = '/' . trim(trim($route), '/');

        return $route === '/' ? '/' : rtrim($route, '/');
    }

    // ------------------------------------------------------------------
    // Termine
    // ------------------------------------------------------------------

    public function eventsPage(): ?PageInterface
    {
        return $this->page(self::EVENTS_ROUTE);
    }

    /** @return EventEntry[] alle Termine inklusive unveröffentlichter */
    public function allEvents(): array
    {
        if ($this->events !== null) {
            return $this->events;
        }

        $parent = $this->eventsPage();
        $events = [];

        if ($parent !== null) {
            foreach ($this->childrenOf($parent) as $child) {
                if ($child->template() === 'event') {
                    $events[] = new EventEntry($child, $this);
                }
            }
        }

        return $this->events = $events;
    }

    /** Termin zu einer Seite (für Templates und Prüfungen) */
    public function event(PageInterface $page): EventEntry
    {
        foreach ($this->allEvents() as $event) {
            if ($event->page()->path() === $page->path()) {
                return $event;
            }
        }

        return new EventEntry($page, $this);
    }

    /**
     * @param EventEntry[] $events
     * @return EventEntry[]
     */
    public function sortEvents(array $events, bool $descending = false): array
    {
        return array_column(
            Calendar::sort(array_map(fn (EventEntry $e) => $e->toCalendarItem(), array_values($events)), $descending),
            'entry'
        );
    }

    /** @return EventEntry[] öffentlich sichtbare Termine, chronologisch */
    public function publicEvents(): array
    {
        return $this->sortEvents(array_filter(
            $this->allEvents(),
            fn (EventEntry $e) => $e->isPublic() && $e->startTimestamp() !== null
        ));
    }

    /** @return EventEntry[] kommende öffentliche Termine */
    public function upcomingEvents(?int $limit = null, bool $withFree = false, bool $withClosed = false, ?int $now = null): array
    {
        $now    = $now ?? time();
        $events = array_values(array_filter($this->publicEvents(), function (EventEntry $e) use ($now, $withFree, $withClosed): bool {
            if ($e->isPast($now)) {
                return false;
            }

            if ($withFree === false && $e->isFree()) {
                return false;
            }

            return $withClosed === true || $e->isClosed() === false;
        }));

        return $limit !== null ? array_slice($events, 0, $limit) : $events;
    }

    /** @return EventEntry[] vergangene öffentliche Termine, neueste zuerst */
    public function pastEvents(?int $limit = null, ?int $now = null): array
    {
        $now    = $now ?? time();
        $events = $this->sortEvents(array_filter(
            $this->publicEvents(),
            fn (EventEntry $e) => $e->isPast($now) && $e->isFree() === false && $e->isClosed() === false
        ), true);

        return $limit !== null ? array_slice($events, 0, $limit) : $events;
    }

    /** Nächster Öffnungstermin: bestätigt, nicht privat oder geschlossen */
    public function nextOpening(?int $now = null): ?EventEntry
    {
        foreach ($this->upcomingEvents(null, false, false, $now) as $event) {
            if ($event->isOpening()) {
                return $event;
            }
        }

        return null;
    }

    /** @return EventEntry[] */
    public function freeEvents(?int $limit = null, ?int $now = null): array
    {
        $events = array_values(array_filter(
            $this->upcomingEvents(null, true, false, $now),
            fn (EventEntry $e) => $e->isFree()
        ));

        return $limit !== null ? array_slice($events, 0, $limit) : $events;
    }

    /**
     * Termine eines Monats oder – ohne Zeitraum – alle kommenden bzw.
     * vergangenen, gefiltert nach Terminart und Verfügbarkeit.
     *
     * @return EventEntry[]
     */
    public function calendarEvents(?int $from, ?int $to, string $category = '', string $availability = '', bool $past = false, ?int $now = null): array
    {
        $now   = $now ?? time();
        $items = array_map(fn (EventEntry $e) => $e->toCalendarItem(), $this->publicEvents());

        if ($from !== null && $to !== null) {
            $items = Calendar::sort(Calendar::between($items, $from, $to));
        } elseif ($past) {
            $items = array_filter(Calendar::past($items, $now), fn (array $item) => $item['public'] !== 'frei');
        } else {
            $items = Calendar::upcoming($items, $now);
        }

        return array_column(Calendar::filter(array_values($items), $category, $availability), 'entry');
    }

    /**
     * Termine nach Monaten gruppiert: [['label' => 'Oktober 2026', 'events' => [...]], …]
     *
     * @param EventEntry[] $events
     */
    public function groupByMonth(array $events): array
    {
        $groups = [];

        foreach ($events as $event) {
            $start = (int)$event->startTimestamp();
            $key   = date('Y-n', $start);
            $groups[$key] ??= [
                'label'  => Calendar::monthLabel((int)date('Y', $start), (int)date('n', $start)),
                'events' => [],
            ];
            $groups[$key]['events'][] = $event;
        }

        return array_values($groups);
    }

    // ------------------------------------------------------------------
    // Thekenteams und Aktuelles
    // ------------------------------------------------------------------

    public function team(string $route): ?TeamEntry
    {
        $route = $this->normalizeRoute($route);

        if (array_key_exists($route, $this->teams) === false) {
            $page = $this->page($route);
            $this->teams[$route] = $page !== null && $page->template() === 'team' ? new TeamEntry($page, $this) : null;
        }

        return $this->teams[$route];
    }

    public function teamFor(PageInterface $page): TeamEntry
    {
        return new TeamEntry($page, $this);
    }

    /** @return TeamEntry[] veröffentlichte Teams mit Einwilligung, alphabetisch */
    public function publicTeams(): array
    {
        $parent = $this->page(self::TEAMS_ROUTE);
        $teams  = [];

        if ($parent !== null) {
            foreach ($this->childrenOf($parent) as $child) {
                if ($child->template() === 'team') {
                    $team = new TeamEntry($child, $this);

                    if ($team->isPublic()) {
                        $teams[] = $team;
                    }
                }
            }
        }

        usort($teams, fn (TeamEntry $a, TeamEntry $b) => strcoll($a->title(), $b->title()));

        return $teams;
    }

    /** Team des nächsten Öffnungstermins oder des nächsten Termins mit Team */
    public function nextTeam(?int $now = null): ?TeamEntry
    {
        $next = $this->nextOpening($now);

        if ($next?->publicTeam() !== null) {
            return $next->publicTeam();
        }

        foreach ($this->upcomingEvents(null, false, false, $now) as $event) {
            if ($event->publicTeam() !== null) {
                return $event->publicTeam();
            }
        }

        return null;
    }

    /** @return PageInterface[] veröffentlichte Meldungen, neueste zuerst */
    public function articles(): array
    {
        $parent   = $this->page(self::NEWS_ROUTE);
        $articles = [];

        if ($parent !== null) {
            foreach ($this->childrenOf($parent) as $child) {
                if ($child->template() === 'article' && $child->published()) {
                    $articles[] = $child;
                }
            }
        }

        usort($articles, fn (PageInterface $a, PageInterface $b) => $this->timestamp($b->header()->date ?? null) <=> $this->timestamp($a->header()->date ?? null));

        return $articles;
    }

    /** Veröffentlichter, öffentlicher Termin zu einer Route oder null */
    public function publicEventByRoute(string $route): ?EventEntry
    {
        $page = $route !== '' ? $this->page($route) : null;

        if ($page === null || $page->template() !== 'event') {
            return null;
        }

        $event = $this->event($page);

        return $event->isPublic() ? $event : null;
    }

    // ------------------------------------------------------------------
    // Anfragen und Redaktion
    // ------------------------------------------------------------------

    public function requestsPage(): ?PageInterface
    {
        return $this->page(self::REQUESTS_ROUTE);
    }

    /** @return PageInterface[] alle gespeicherten Anfragen */
    public function requests(): array
    {
        $parent = $this->requestsPage();

        if ($parent === null) {
            return [];
        }

        return array_values(array_filter(
            $this->childrenOf($parent),
            fn (PageInterface $p) => $p->template() === 'request'
        ));
    }

    /** @return PageInterface[] Anfragen mit Stand „neu“ oder „in Bearbeitung“, älteste zuerst */
    public function openRequests(): array
    {
        $open = array_filter($this->requests(), function (PageInterface $p): bool {
            $state = (string)($p->header()->processing ?? 'neu');
            return in_array($state, ['neu', 'inbearbeitung'], true);
        });

        usort($open, fn (PageInterface $a, PageInterface $b) => strcmp((string)($a->header()->submittedat ?? ''), (string)($b->header()->submittedat ?? '')));

        return array_values($open);
    }

    /** @return EventEntry[] */
    public function eventsAwaitingApproval(): array
    {
        return $this->sortEvents(array_filter(
            $this->allEvents(),
            fn (EventEntry $e) => $e->orgStatus() === 'freigabe'
                || ($e->isPublished() === false && in_array($e->orgStatus(), EventStatus::APPROVAL_REQUIRED, true))
        ));
    }

    /** @return EventEntry[] */
    public function incompleteEvents(?int $now = null): array
    {
        return $this->sortEvents(array_filter(
            $this->allEvents(),
            fn (EventEntry $e) => $e->isPast($now) === false && EventStatus::isActive($e->orgStatus()) && $e->missingInfo() !== []
        ));
    }

    /** @return EventEntry[] */
    public function conflictingEvents(?int $now = null): array
    {
        return $this->sortEvents(array_filter(
            $this->allEvents(),
            fn (EventEntry $e) => $e->isPast($now) === false && $e->conflicts() !== []
        ));
    }

    /** Hinweise für die Administration im Dashboard */
    public function setupWarnings(): array
    {
        $warnings = [];

        if ($this->requestRecipient() === null) {
            $warnings[] = 'Keine Empfängeradresse für Terminanfragen gepflegt (Einstellungen).';
        }

        if ($this->mailEnabled() === false) {
            $warnings[] = 'Mailversand ist abgeschaltet (plugins.email.mailer.engine = none). Anfragen werden nur im Admin gespeichert.';
        }

        if ($this->grav['config']->get('system.custom_base_url') === '' && $this->environment() !== 'dev') {
            $warnings[] = 'Keine öffentliche Basis-URL gesetzt (GRAV_CONFIG__system__custom_base_url).';
        }

        $missing = $this->missingMasterData();

        if ($missing !== []) {
            $warnings[] = 'Stammdaten fehlen noch: ' . implode(', ', $missing) . '.';
        }

        return $warnings;
    }

    // ------------------------------------------------------------------
    // Stammdaten und Einstellungen (Seite „Einstellungen“)
    // ------------------------------------------------------------------

    public function settingsPage(): ?PageInterface
    {
        return $this->page(self::SETTINGS_ROUTE);
    }

    /** Alle Stammdaten und Einstellungen als Array */
    public function settings(): array
    {
        if ($this->settings === null) {
            $page           = $this->settingsPage();
            $this->settings = $page !== null ? (array)$page->header() : [];
        }

        return $this->settings;
    }

    /** Einzelner Text aus den Einstellungen */
    public function setting(string $key, string $default = ''): string
    {
        $value = $this->settings()[$key] ?? null;

        return is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : $default;
    }

    /** Liste aus den Einstellungen (z. B. Öffnungszeiten) */
    public function settingRows(string $key): array
    {
        $value = $this->settings()[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    public function missingMasterData(): array
    {
        $missing = [];

        foreach (self::MASTER_DATA as $key => $label) {
            if ($this->setting($key) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public function approvalMode(): bool
    {
        $value = $this->settings()['approvalmode'] ?? true;

        return is_bool($value) ? $value : in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    public function requestRecipient(): ?string
    {
        $email = $this->setting('requestrecipient', $this->setting('email'));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    public function retentionDays(): int
    {
        $days = (int)$this->setting('retentiondays', '180');

        return $days > 0 ? min($days, 3650) : 180;
    }

    public function sendReceipt(): bool
    {
        $value = $this->settings()['requestreceipt'] ?? true;

        return is_bool($value) ? $value : in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    public function name(): string
    {
        return $this->setting('name', (string)($this->grav['config']->get('site.title') ?: 'Ehrenamtskneipe Erndtebrück'));
    }

    public function claim(): string
    {
        return $this->setting('claim', 'Von Erndtebrück. Für Erndtebrück. Zusammen.');
    }

    public function description(): string
    {
        return $this->setting('description');
    }

    public function addressLine(): string
    {
        return trim(implode(', ', array_filter([
            $this->setting('street'),
            trim($this->setting('postalcode') . ' ' . $this->setting('city')),
        ])));
    }

    public function hasFullAddress(): bool
    {
        return $this->setting('street') !== '' && $this->setting('postalcode') !== '' && $this->setting('city') !== '';
    }

    /**
     * Externer Kartenlink (OpenStreetMap) – nur ein Link, keine eingebettete
     * Karte. Mit Koordinaten punktgenau, sonst Adresssuche.
     */
    public function mapUrl(): ?string
    {
        [$lat, $lon] = $this->coordinates();

        if ($lat !== null && $lon !== null) {
            return 'https://www.openstreetmap.org/?mlat=' . rawurlencode($lat) . '&mlon=' . rawurlencode($lon)
                . '#map=18/' . rawurlencode($lat) . '/' . rawurlencode($lon);
        }

        $query = $this->addressLine();

        return $query === '' ? null : 'https://www.openstreetmap.org/search?query=' . rawurlencode($query);
    }

    /** @return array{0: ?string, 1: ?string} */
    public function coordinates(): array
    {
        $lat = str_replace(',', '.', $this->setting('latitude'));
        $lon = str_replace(',', '.', $this->setting('longitude'));

        return is_numeric($lat) && is_numeric($lon) ? [$lat, $lon] : [null, null];
    }

    /** Strukturierte Daten der Kneipe – nur mit vollständiger Anschrift */
    public function organizationJsonLd(): ?array
    {
        if ($this->hasFullAddress() === false) {
            return null;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'BarOrPub',
            'name'     => $this->name(),
            'url'      => $this->absoluteUrl('/'),
            'address'  => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $this->setting('street'),
                'postalCode'      => $this->setting('postalcode'),
                'addressLocality' => $this->setting('city'),
                'addressCountry'  => 'DE',
            ],
        ];

        if ($this->setting('email') !== '') {
            $data['email'] = $this->setting('email');
        }

        if ($this->setting('phone') !== '') {
            $data['telephone'] = $this->setting('phone');
        }

        [$lat, $lon] = $this->coordinates();

        if ($lat !== null && $lon !== null) {
            $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float)$lat, 'longitude' => (float)$lon];
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Technik
    // ------------------------------------------------------------------

    public function environment(): string
    {
        return (string)$this->grav['config']->get('setup.environment', Env::get('GRAV_ENVIRONMENT') ?? '');
    }

    public function noindex(): bool
    {
        return Env::bool('KNEIPE_NOINDEX', (bool)$this->grav['config']->get('plugins.kneipe.noindex', false));
    }

    public function mailEnabled(): bool
    {
        return $this->grav['config']->get('plugins.email.enabled', false) === true
            && $this->grav['config']->get('plugins.email.mailer.engine', 'none') !== 'none';
    }

    public function rateLimit(): int
    {
        return Env::int('KNEIPE_RATE_LIMIT', (int)$this->grav['config']->get('plugins.kneipe.requests.rate_limit', 5), 1, 1000);
    }

    public function minSeconds(): int
    {
        return Env::int('KNEIPE_MIN_SECONDS', (int)$this->grav['config']->get('plugins.kneipe.requests.min_seconds', 3), 0, 600);
    }

    /**
     * Schlüssel für Zeitfalle und Rate-Limit, abgeleitet aus Gravs geheimem
     * Nonce-Schlüssel (security-private.php) – kein eigenes Geheimnis nötig.
     */
    public function secret(): string
    {
        return hash_hmac('sha256', 'kneipe', Security::getNonceKey());
    }

    /** Verzeichnis für Laufzeitdaten des Plugins (Rate-Limit, Wartung) */
    public function dataDir(string $sub = ''): string
    {
        $base = (string)$this->grav['locator']->findResource('user-data://', true, true) . '/kneipe';

        return $sub === '' ? $base : $base . '/' . $sub;
    }

    public function host(): string
    {
        return (string)(parse_url($this->absoluteUrl('/'), PHP_URL_HOST) ?: 'localhost');
    }

    public function absoluteUrl(string $url): string
    {
        if (preg_match('~^https?://~i', $url) === 1) {
            return $url;
        }

        $base = rtrim((string)$this->grav['config']->get('system.custom_base_url', ''), '/');

        if ($base === '') {
            $base = rtrim((string)$this->grav['uri']->rootUrl(true), '/');
        } else {
            // custom_base_url enthält bereits einen eventuellen Unterpfad
            $root = rtrim((string)$this->grav['uri']->rootUrl(false), '/');
            if ($root !== '' && str_starts_with($url, $root . '/')) {
                $url = substr($url, strlen($root));
            }
        }

        return $base . '/' . ltrim($url, '/');
    }

    public function url(string $route): string
    {
        $base = rtrim((string)$this->grav['uri']->rootUrl(false), '/');

        return $base . '/' . ltrim($route, '/');
    }

    /** Aktuelle Anfrage ist eine gültige Admin-Vorschau für $route */
    public function isPreview(string $route): bool
    {
        $token = $_GET['preview_token'] ?? null;

        if (!is_string($token) || $token === '' || !isset($_GET['admin_preview'])) {
            return false;
        }

        if (class_exists(\Grav\Plugin\Api\Auth\JwtAuthenticator::class) === false) {
            return false;
        }

        try {
            $validated = (new \Grav\Plugin\Api\Auth\JwtAuthenticator($this->grav, $this->grav['config']))->validatePreviewToken($token);
        } catch (\Throwable) {
            return false;
        }

        return $validated !== null && $this->normalizeRoute($validated) === $this->normalizeRoute($route);
    }

    // ------------------------------------------------------------------
    // Bilder
    // ------------------------------------------------------------------

    /** Bild einer Seite nach Dateiname (Feld mit einem Dateinamen) */
    public function image(PageInterface $page, mixed $filename): ?object
    {
        if (is_array($filename)) {
            $filename = reset($filename);
        }

        if (!is_string($filename) || trim($filename) === '') {
            return null;
        }

        $media = $page->media()->all();

        return $media[basename(trim($filename))] ?? null;
    }

    /** @return object[] Bilder einer Seite aus einer Liste von Dateinamen */
    public function images(PageInterface $page, mixed $filenames): array
    {
        $list = [];

        foreach (is_array($filenames) ? $filenames : [] as $item) {
            $name  = is_array($item) ? ($item['image'] ?? $item['file'] ?? null) : $item;
            $image = $this->image($page, $name);

            if ($image !== null) {
                $list[] = $image;
            }
        }

        return $list;
    }

    /**
     * Attribute für ein responsives Bild: src, srcset, width, height, alt.
     * Mit $ratio wird auf das Seitenverhältnis zugeschnitten.
     */
    public function imageAttrs(?object $image, ?float $ratio = null, int $maxWidth = 1200): ?array
    {
        if ($image === null || method_exists($image, 'url') === false) {
            return null;
        }

        $alt      = $this->imageMeta($image, 'alt');
        $width    = (int)($image->get('width') ?? 0);
        $height   = (int)($image->get('height') ?? 0);
        $isRaster = $width > 0 && $height > 0 && method_exists($image, 'cropZoom') && $image->get('extension') !== 'svg';

        if ($isRaster === false) {
            return ['src' => $image->url(), 'srcset' => null, 'width' => null, 'height' => null, 'alt' => $alt];
        }

        $ratio  = $ratio ?? $width / $height;
        $set    = [];
        $main   = null;
        $widths = array_values(array_unique(array_filter(
            [480, 800, $maxWidth, (int)round($maxWidth * 1.5)],
            fn (int $w) => $w <= max($width, 480)
        )));

        try {
            foreach ($widths as $w) {
                $h     = (int)round($w / $ratio);
                $clone = clone $image;
                $clone->cropZoom($w, $h);
                $url   = $clone->url();
                $set[] = $url . ' ' . $w . 'w';

                // Hauptbild: größte Breite bis $maxWidth
                if ($main === null || ($w <= $maxWidth && $w > $main['width'])) {
                    $main = ['src' => $url, 'width' => $w, 'height' => $h];
                }
            }
        } catch (\Throwable $e) {
            // Bildbearbeitung nicht verfügbar (z. B. ohne gd): Original ausliefern
            $this->grav['log']->warning('[kneipe] Bildvarianten nicht erzeugt: ' . $e::class);

            return ['src' => $image->url(), 'srcset' => null, 'width' => $width, 'height' => $height, 'alt' => $alt];
        }

        return [
            'src'    => $main['src'],
            'srcset' => implode(', ', $set),
            'width'  => $main['width'],
            'height' => $main['height'],
            'alt'    => $alt,
        ];
    }

    public function imageMeta(object $image, string $key): string
    {
        $value = method_exists($image, 'get') ? ($image->get($key) ?? $image->get('meta.' . $key)) : null;

        return is_scalar($value) ? trim((string)$value) : '';
    }

    // ------------------------------------------------------------------
    // Datum und Text
    // ------------------------------------------------------------------

    public function formatDate(?int $timestamp, string $pattern = 'EEEE, d. MMMM y'): string
    {
        if ($timestamp === null) {
            return '';
        }

        if (class_exists(IntlDateFormatter::class) === false) {
            return self::formatDateFallback($timestamp, $pattern);
        }

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

    /**
     * Ersatz ohne intl-Erweiterung für die im Theme verwendeten Muster
     * (EEEE, EEE, d, MMMM, MMM, y).
     */
    public static function formatDateFallback(int $timestamp, string $pattern): string
    {
        $weekday = Calendar::WEEKDAYS_LONG[(int)date('N', $timestamp) - 1];
        $month   = Calendar::MONTHS[(int)date('n', $timestamp)];

        return preg_replace_callback('/EEEE|EEE|MMMM|MMM|d|y/', fn (array $m) => match ($m[0]) {
            'EEEE' => $weekday,
            'EEE'  => mb_substr($weekday, 0, 2) . '.',
            'MMMM' => $month,
            'MMM'  => mb_strlen($month) > 4 ? mb_substr($month, 0, 3) . '.' : $month,
            'd'    => date('j', $timestamp),
            'y'    => date('Y', $timestamp),
        }, $pattern) ?? date('d.m.Y', $timestamp);
    }

    public function timeRange(EventEntry $event): string
    {
        if ($event->isAllDay()) {
            return 'ganztägig';
        }

        $start = $event->startTimestamp();

        if ($start === null) {
            return '';
        }

        $text = date('H:i', $start);

        if ($event->hasEndTime()) {
            $text .= '–' . date('H:i', $event->endTimestamp());
        }

        return $text . ' Uhr';
    }

    public function formatDateTime(EventEntry $event): string
    {
        $start = $event->startTimestamp();

        return $start === null ? 'ohne Datum' : $this->formatDate($start, 'EEE, d. MMM y') . ', ' . $this->timeRange($event);
    }

    /**
     * Rechtstexte: „(stammdaten: street)“ durch den Wert aus den
     * Einstellungen ersetzen oder – wenn er fehlt – deutlich markieren.
     * Das HTML wird vorher bereinigt (Richtext).
     */
    public function legalHtml(string $html): string
    {
        $html = Richtext::sanitize($html);

        $labels = [
            'name'           => 'Name',
            'operator'       => 'Betreiber/Träger',
            'representative' => 'Vertretungsberechtigte Person(en)',
            'street'         => 'Straße und Hausnummer',
            'postalcode'     => 'Postleitzahl',
            'city'           => 'Ort',
            'address'        => 'Anschrift',
            'email'          => 'E-Mail-Adresse',
            'phone'          => 'Telefonnummer',
            'register'       => 'Registereintrag',
            'responsible'    => 'Verantwortlich nach § 18 Abs. 2 MStV',
            'hoster'         => 'Hosting-Anbieter',
            'mailprovider'   => 'E-Mail-Anbieter',
            'retention'      => 'Löschfrist',
        ];

        $html = preg_replace_callback('/\(stammdaten:\s*([a-z]+)\s*\)/u', function (array $m) use ($labels): string {
            $key   = strtolower($m[1]);
            $value = match ($key) {
                'name'           => $this->name(),
                'address'        => $this->addressLine(),
                'retention'      => (string)$this->retentionDays(),
                'representative' => $this->setting('legalrepresentative'),
                'register'       => $this->setting('legalregister'),
                'responsible'    => $this->setting('legalresponsible'),
                'hoster'         => $this->setting('legalhoster'),
                'mailprovider'   => $this->setting('legalmailprovider'),
                default          => isset($labels[$key]) ? $this->setting($key) : '',
            };

            if (trim($value) === '') {
                return '<mark class="placeholder">[' . htmlspecialchars($labels[$key] ?? $key, ENT_QUOTES, 'UTF-8') . ' – noch eintragen]</mark>';
            }

            $escaped = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

            return match ($key) {
                'email' => '<a href="mailto:' . $escaped . '">' . $escaped . '</a>',
                'phone' => '<a href="tel:' . htmlspecialchars(preg_replace('/[^0-9+]/', '', $value) ?? '', ENT_QUOTES, 'UTF-8') . '">' . $escaped . '</a>',
                default => nl2br($escaped, false),
            };
        }, $html) ?? $html;

        return self::markPlaceholders($html);
    }

    /** Markdown aus einem Zusatzfeld sicher als HTML ausgeben */
    public static function richtext(mixed $markdown): string
    {
        if (!is_string($markdown) || trim($markdown) === '') {
            return '';
        }

        $parser = new \Parsedown();
        $parser->setSafeMode(true);
        $parser->setMarkupEscaped(true);
        $parser->setBreaksEnabled(false);

        return self::markPlaceholders(Richtext::sanitize($parser->text($markdown)));
    }

    public function timestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) ? (int)strtotime($value) : 0;
    }

    /** Hebt „[Platzhalter: …]“ in bereits maskiertem HTML hervor */
    public static function markPlaceholders(string $html): string
    {
        return preg_replace('/\[(Platzhalter:[^\]<]*)\]/u', '<mark class="placeholder">[$1]</mark>', $html) ?? $html;
    }

    /** Text maskieren, Zeilenumbrüche erhalten, Platzhalter hervorheben */
    public static function text(mixed $text, bool $breaks = true): string
    {
        $html = htmlspecialchars(is_scalar($text) ? (string)$text : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::markPlaceholders($breaks ? nl2br($html, false) : $html);
    }

    // ------------------------------------------------------------------

    /** @return PageInterface[] alle Unterseiten, auch unveröffentlichte */
    private function childrenOf(PageInterface $parent): array
    {
        $children = [];

        foreach ($parent->children() as $child) {
            if ($child instanceof PageInterface) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
