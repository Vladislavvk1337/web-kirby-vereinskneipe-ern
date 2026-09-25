<?php

namespace Grav\Plugin\Kneipe;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Utils;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Ablauf des Formulars „Freien Termin anfragen“.
 *
 * Schutz: CSRF-Nonce (Grav-Sitzung), Honeypot, Zeitfalle, Herkunfts-
 * prüfung und Rate-Limit je IP-Hash. Erfolgreiche Anfragen werden als nicht
 * veröffentlichte, nicht routbare Seite unter /anfragen gespeichert (nie
 * öffentlich) und per E-Mail gemeldet. Danach Post/Redirect/Get auf die
 * Bestätigungsseite. Formularinhalte gelangen weder in URLs noch in Logs.
 */
final class RequestForm
{
    public const HONEYPOT     = 'website';
    public const TIMER        = 'formstart';
    public const NONCE_FIELD  = 'csrf';
    public const NONCE_ACTION = 'kneipe-request';

    public function __construct(private Grav $grav, private Service $service)
    {
    }

    /** Freie Termine für die Auswahl: Slug => Beschriftung */
    public function slots(?int $now = null): array
    {
        $slots = [];

        foreach ($this->service->freeEvents(null, $now) as $event) {
            $slots[$event->slug()] = $this->service->formatDateTime($event);
        }

        return $slots;
    }

    public function nonce(): string
    {
        return Utils::getNonce(self::NONCE_ACTION);
    }

    public function timer(): string
    {
        return FormTimer::issue($this->service->secret());
    }

    /**
     * Verarbeitet einen POST. Rückgabe:
     *   ['status' => 'ok'|'spam'|'invalid'|'csrf'|'ratelimit'|'origin'|'error', 'values' => [], 'errors' => []]
     */
    public function handle(array $input, ?string $origin, string $clientIp): array
    {
        $values = array_map(
            fn ($value) => is_string($value) ? mb_substr($value, 0, 3000) : '',
            array_intersect_key($input, RequestValidator::FIELDS)
        );

        if ($this->originAllowed($origin) === false) {
            return ['status' => 'origin', 'values' => $values, 'errors' => []];
        }

        $nonce = $input[self::NONCE_FIELD] ?? null;

        if (!is_string($nonce) || Utils::verifyNonce($nonce, self::NONCE_ACTION) !== true) {
            return ['status' => 'csrf', 'values' => $values, 'errors' => []];
        }

        // Honeypot: für Menschen unsichtbar, Bots füllen es aus
        if (trim(is_string($input[self::HONEYPOT] ?? null) ? $input[self::HONEYPOT] : '') !== '') {
            return ['status' => 'spam', 'values' => [], 'errors' => []];
        }

        $slots  = $this->slots();
        $result = RequestValidator::validate($input, $slots, date('Y-m-d'));

        // Unvollständige Eingaben immer mit Fehlermeldungen beantworten –
        // auch wenn sehr schnell abgeschickt wurde
        if ($result['errors'] !== []) {
            return ['status' => 'invalid', 'values' => $result['values'], 'errors' => $result['errors']];
        }

        // Zeitfalle: vollständig ausgefüllt schneller als ein Mensch tippen kann
        $elapsed = FormTimer::elapsed(is_string($input[self::TIMER] ?? null) ? $input[self::TIMER] : '', $this->service->secret());

        if ($elapsed === null || $elapsed < $this->service->minSeconds()) {
            return ['status' => 'spam', 'values' => [], 'errors' => []];
        }

        // Gezählt werden nur vollständige Anfragen – Tippfehler sperren niemanden aus
        $limiter = $this->limiter();

        if ($limiter->hit($clientIp) === false) {
            return ['status' => 'ratelimit', 'values' => $result['values'], 'errors' => []];
        }

        try {
            $slug = $this->store($result['values'], $slots);
        } catch (Throwable $e) {
            $this->log('Anfrage konnte nicht gespeichert werden: ' . $e::class . ' in ' . basename($e->getFile()) . ':' . $e->getLine());

            return ['status' => 'error', 'values' => $result['values'], 'errors' => []];
        }

        $this->notify($slug, $result['values'], $slots);

        // Gelegenheit nutzen, abgelaufene Anfragen und Zähler zu entfernen
        Retention::cleanup($this->service, $this->service->retentionDays());
        $limiter->prune();

        return ['status' => 'ok', 'values' => [], 'errors' => []];
    }

    public function limiter(): RateLimiter
    {
        return new RateLimiter($this->service->dataDir('ratelimit'), $this->service->secret(), $this->service->rateLimit(), 3600);
    }

    /** Herkunft prüfen, wenn der Browser sie mitschickt (Origin-Header) */
    public function originAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '' || $origin === 'null') {
            return true;
        }

        $expected = [
            parse_url($this->service->absoluteUrl('/'), PHP_URL_HOST),
            $this->grav['uri']->host(),
        ];
        $actual = parse_url($origin, PHP_URL_HOST);

        return is_string($actual) && in_array(strtolower($actual), array_map('strtolower', array_filter($expected)), true);
    }

    /**
     * Speichert die Anfrage als Seite /anfragen/anfrage-…/request.md.
     *
     * @return string Ordnername der Anfrage
     */
    public function store(array $values, array $slots): string
    {
        $parent = $this->service->requestsPage();

        if ($parent === null) {
            throw new \RuntimeException('Seite „anfragen“ fehlt.');
        }

        $isOther   = $values['slot'] === RequestValidator::OTHER;
        $slotLabel = $isOther
            ? 'Eigener Vorschlag: ' . date('d.m.Y', (int)strtotime($values['wishdate']))
            : ($slots[$values['slot']] ?? $values['slot']);

        $event = $isOther ? null : $this->findEventBySlug($values['slot']);
        $slug  = 'anfrage-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $dir   = rtrim((string)$parent->path(), '/') . '/' . $slug;

        Folder::create($dir);

        $page = new Page();
        $page->filePath($dir . '/request.md');
        $page->header((object)[
            'title'       => 'Anfrage ' . $values['groupname'] . ' – ' . $slotLabel,
            'published'   => false,
            'routable'    => false,
            'visible'     => false,
            'slotlabel'   => $slotLabel,
            'slotpage'    => $event?->id() ?? '',
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
        ]);
        $page->rawMarkdown('');
        $page->save(false);

        $pages = $this->grav['pages'];

        if (method_exists($pages, 'markChanged')) {
            $pages->markChanged();
        }

        $this->service->flush();

        return $slug;
    }

    /**
     * Benachrichtigung an die Redaktion und optionale Eingangsbestätigung.
     * Fehler beim Versand verhindern die Speicherung nicht; protokolliert
     * wird nur die technische Ursache ohne Formularinhalte.
     */
    public function notify(string $slug, array $values, array $slots): void
    {
        if ($this->service->mailEnabled() === false || !isset($this->grav['Email'])) {
            return;
        }

        $config   = $this->grav['config'];
        $from     = (string)($config->get('plugins.email.from') ?: 'noreply@' . $this->service->host());
        $fromName = (string)($config->get('plugins.email.from_name') ?: $this->service->name());
        $name     = $this->service->name();
        $sender   = new Address($from, $fromName);

        if (($recipient = $this->service->requestRecipient()) !== null) {
            $lines = [
                'Neue Terminanfrage über die Website ' . $name,
                '',
                'Gruppe:        ' . $values['groupname'],
                'Wunschtermin:  ' . ($values['slot'] === RequestValidator::OTHER
                    ? 'Eigener Vorschlag: ' . date('d.m.Y', (int)strtotime($values['wishdate']))
                    : ($slots[$values['slot']] ?? $values['slot'])),
            ];

            if ($values['altdate'] !== '') {
                $lines[] = 'Alternative:   ' . date('d.m.Y', (int)strtotime($values['altdate']));
            }

            array_push(
                $lines,
                '',
                'Alle Angaben stehen im Admin unter „Anfragen“:',
                $this->service->absoluteUrl('/admin/pages/edit' . Service::REQUESTS_ROUTE . '/' . $slug),
                '',
                'Auf diese E-Mail antworten erreicht die anfragende Person direkt.',
                'Bitte keine Daten aus der Anfrage an Dritte weitergeben. Die Anfrage wird',
                'nach Ablauf der Löschfrist automatisch gelöscht.',
            );

            $this->send(
                fn () => $this->grav['Email']->message('Neue Terminanfrage: ' . mb_substr($values['groupname'], 0, 60), implode("\n", $lines), 'text/plain')
                    ->from($sender)->to($recipient)->replyTo($values['email']),
                'Benachrichtigung zur Terminanfrage ' . $slug
            );
        }

        if ($this->service->sendReceipt()) {
            $body = implode("\n", [
                'Hallo,',
                '',
                'vielen Dank für eure Anfrage bei ' . $name . '!',
                '',
                'Sie ist bei uns angekommen. Die Redaktion meldet sich in den nächsten',
                'Tagen bei euch. Bis dahin ist der Termin noch nicht fest vergeben.',
                '',
                'Eure Angaben verwenden wir nur zur Bearbeitung der Anfrage. Sie werden',
                'nach ' . $this->service->retentionDays() . ' Tagen automatisch gelöscht.',
                '',
                'Viele Grüße',
                $name,
                $this->service->absoluteUrl('/'),
                '',
                'Ihr habt diese Anfrage nicht gestellt? Dann könnt ihr diese E-Mail',
                'einfach ignorieren.',
            ]);

            $this->send(
                fn () => $this->grav['Email']->message('Eure Terminanfrage bei ' . $name, $body, 'text/plain')
                    ->from($sender)->to($values['email']),
                'Eingangsbestätigung zur Terminanfrage ' . $slug
            );
        }
    }

    private function send(callable $build, string $what): void
    {
        try {
            $sent = $this->grav['Email']->send($build());

            if ($sent < 1) {
                $this->log($what . ' nicht versendet.');
            }
        } catch (Throwable $e) {
            $this->log($what . ' nicht versendet: ' . $e::class);
        }
    }

    private function findEventBySlug(string $slug): ?EventEntry
    {
        foreach ($this->service->allEvents() as $event) {
            if ($event->slug() === $slug) {
                return $event;
            }
        }

        return null;
    }

    private function log(string $message): void
    {
        $this->grav['log']->warning('[kneipe] ' . $message);
    }
}
