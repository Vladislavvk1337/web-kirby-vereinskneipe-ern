<?php

namespace Grav\Plugin;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Kneipe\CalendarView;
use Grav\Plugin\Kneipe\Env;
use Grav\Plugin\Kneipe\Feeds;
use Grav\Plugin\Kneipe\Guard;
use Grav\Plugin\Kneipe\Health;
use Grav\Plugin\Kneipe\OverviewController;
use Grav\Plugin\Kneipe\RequestForm;
use Grav\Plugin\Kneipe\Service;
use Grav\Plugin\Kneipe\TwigHelpers;
use RocketTheme\Toolbox\Event\Event;

/**
 * Projekt-Plugin „kneipe“: Terminlogik, Freigabeworkflow, Anfrageformular,
 * iCalendar, Sitemap/robots.txt, Health-Checks und die Redaktions-
 * übersicht im Admin.
 *
 * Reine Logik steckt in classes/ (größtenteils ohne Grav testbar), die
 * Verbindung zu Grav in dieser Datei.
 */
class KneipePlugin extends Plugin
{
    /** Seiten, die nie öffentlich ausgeliefert werden */
    private const HIDDEN_TEMPLATES = ['request', 'requests', 'settings'];

    /** Frontend-Routen des Login-Plugins – Anmeldung läuft nur über /admin */
    private const BLOCKED_ROUTES = [
        '/login', '/forgot_password', '/reset_password', '/activate_user', '/user_profile',
        '/user_register', '/magic_login', '/magic_link', '/user_unauthorized',
    ];

    private ?array $formState = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100001],
                ['onConfigureLogging', 100000],
                // vor Admin2 (1001), das ohne Konten alles nach /admin umleitet
                ['onHealthCheck', 99999],
                ['onPluginsInitialized', 0],
            ],
        ];
    }

    public function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\Kneipe\\';

            if (str_starts_with($class, $prefix)) {
                $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

                if (is_file($file)) {
                    require $file;
                }
            }
        });
    }

    /**
     * Im Container Grav-Meldungen (ab Warnung) nach stderr statt in eine
     * wachsende Datei schreiben – kubectl logs zeigt sie dann (KNEIPE_LOG_STDERR).
     */
    public function onConfigureLogging(): void
    {
        if (Env::bool('KNEIPE_LOG_STDERR') === false) {
            return;
        }

        $logger = $this->grav['log'] ?? null;

        if ($logger instanceof \Monolog\Logger) {
            $logger->setHandlers([new \Monolog\Handler\StreamHandler('php://stderr', \Monolog\Level::Warning)]);
        }
    }

    /** /healthz und /readyz beantworten, bevor andere Plugins umleiten */
    public function onHealthCheck(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $path = $this->requestPath();

        if ($path === '/healthz') {
            // Liveness: PHP und Grav antworten – ohne Seiten zu laden
            $this->respond(Health::live(), 'text/plain; charset=utf-8', Health::HEADERS);
        }

        if ($path === '/readyz') {
            [$ready, $body] = Health::ready(Service::instance($this->grav));
            $this->respond($body, 'text/plain; charset=utf-8', Health::HEADERS, $ready ? 200 : 503);
        }
    }

    public function onPluginsInitialized(): void
    {
        // Admin2/REST-API: Freigabeworkflow und Redaktionsübersicht
        $this->enable([
            'onAdminSave'                => ['onAdminSave', -100],
            'onApiBeforePageDelete'      => ['onApiBeforePageDelete', 0],
            'onApiPageCreated'           => ['onApiPageCreated', 0],
            'onApiPageMoved'             => ['onApiPageMoved', 0],
            'onApiBeforePagesReorder'    => ['onApiBeforePagesReorder', 0],
            'onApiBeforePagesReorganize' => ['onApiBeforePagesReorganize', 0],
            'onApiBeforePageTranslate'   => ['onApiBeforePageTranslate', 0],
            'onApiPageUpdated'           => ['flush', 0],
            'onApiPageDeleted'           => ['flush', 0],
            'onApiRegisterRoutes'        => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems'          => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo'        => ['onApiPluginPageInfo', 0],
        ]);

        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onPagesInitialized'  => ['onPagesInitialized', 0],
            'onPageInitialized'   => ['onPageInitialized', 1000],
            'onTwigInitialized'   => ['onTwigInitialized', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ]);
    }

    // ------------------------------------------------------------------
    // Admin2 / API
    // ------------------------------------------------------------------

    public function onAdminSave(Event $event): void
    {
        (new Guard($this->grav, Service::instance($this->grav)))->onAdminSave($event);
    }

    public function onApiBeforePageDelete(Event $event): void
    {
        (new Guard($this->grav, Service::instance($this->grav)))->onApiBeforePageDelete($event);
    }

    public function onApiPageCreated(Event $event): void
    {
        (new Guard($this->grav, Service::instance($this->grav)))->onApiPageCreated($event);
    }

    public function onApiPageMoved(Event $event): void
    {
        (new Guard($this->grav, Service::instance($this->grav)))->onApiPageMoved($event);
    }

    public function onApiBeforePagesReorder(Event $event): void
    {
        (new Guard($this->grav, Service::instance($this->grav)))->onApiBeforePagesReorder($event);
    }

    public function onApiBeforePagesReorganize(Event $event): void
    {
        (new Guard($this->grav, Service::instance($this->grav)))->onApiBeforePagesReorganize($event);
    }

    public function onApiBeforePageTranslate(Event $event): void
    {
        (new Guard($this->grav, Service::instance($this->grav)))->onApiBeforePageTranslate($event);
    }

    public function flush(): void
    {
        Service::instance($this->grav)->flush();
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        $event['routes']->get('/kneipe/overview', [OverviewController::class, 'overview']);
    }

    public function onApiSidebarItems(Event $event): void
    {
        $items   = $event['items'];
        $open    = count(Service::instance($this->grav)->openRequests());
        $items[] = [
            'id'       => 'kneipe',
            'plugin'   => 'kneipe',
            'label'    => 'Redaktion',
            'icon'     => 'fa-clipboard-list',
            'route'    => '/plugin/kneipe',
            'priority' => 1,
            'badge'    => $open > 0 ? (string)$open : null,
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'kneipe') {
            return;
        }

        $event['definition'] = [
            'id'        => 'kneipe',
            'plugin'    => 'kneipe',
            'title'     => 'Redaktionsübersicht',
            'icon'      => 'fa-clipboard-list',
            'page_type' => 'component',
        ];
    }

    // ------------------------------------------------------------------
    // Website
    // ------------------------------------------------------------------

    public function onPagesInitialized(): void
    {
        $service = Service::instance($this->grav);
        $path    = $this->requestPath();

        switch ($path) {
            case '/robots.txt':
                $this->respond((new Feeds($service))->robots(), 'text/plain; charset=utf-8', ['Cache-Control' => 'public, max-age=3600']);
                // no break – respond() beendet die Anfrage
            case '/sitemap.xml':
                $this->respond((new Feeds($service))->sitemap(), 'application/xml; charset=utf-8', ['Cache-Control' => 'public, max-age=3600']);
                // no break
            case Service::EVENTS_ROUTE . '.ics':
                $this->respond((new Feeds($service))->icsFeed(), 'text/calendar; charset=utf-8', [
                    'Cache-Control'       => 'public, max-age=900',
                    'Content-Disposition' => 'inline; filename="termine.ics"',
                ]);
        }

        // Einzelner Termin: /termine/<termin>.ics
        if (preg_match('~^' . preg_quote(Service::EVENTS_ROUTE, '~') . '/([a-z0-9-]+)\.ics$~', $path, $match) === 1) {
            $page = $service->page(Service::EVENTS_ROUTE . '/' . $match[1]);
            $ics  = $page !== null && $page->template() === 'event' ? (new Feeds($service))->icsEvent($service->event($page)) : null;

            if ($ics !== null) {
                $this->respond($ics, 'text/calendar; charset=utf-8', [
                    'Content-Disposition' => 'attachment; filename="' . $match[1] . '.ics"',
                ]);
            }

            $this->notFound();
        }
    }

    /**
     * Sichtbarkeit (Termine, Teams, interne Seiten) und das Anfrageformular.
     */
    public function onPageInitialized(Event $event): void
    {
        /** @var PageInterface|null $page */
        $page = $event['page'] ?? $this->grav['page'] ?? null;

        if (!$page instanceof PageInterface) {
            return;
        }

        $service  = Service::instance($this->grav);
        $template = (string)$page->template();
        $route    = $service->normalizeRoute((string)$page->route());

        if (in_array($this->requestPath(), self::BLOCKED_ROUTES, true)
            || in_array($template, self::HIDDEN_TEMPLATES, true)
            || str_starts_with($route . '/', Service::REQUESTS_ROUTE . '/')
            || str_starts_with($route . '/', Service::SETTINGS_ROUTE . '/')) {
            $page->routable(false);

            return;
        }

        // Nicht öffentliche Termine und Teams gibt es für Gäste nicht;
        // die Admin-Vorschau zeigt sie gekennzeichnet.
        $preview = $service->isPreview($route);

        if ($template === 'event' && $service->event($page)->isPublic() === false && $preview === false) {
            $page->routable(false);

            return;
        }

        if ($template === 'team' && $service->teamFor($page)->isPublic() === false && $preview === false) {
            $page->routable(false);

            return;
        }

        if ($template === 'requestform') {
            $this->handleRequestForm($page, $service);
        }
    }

    private function handleRequestForm(PageInterface $page, Service $service): void
    {
        $form  = new RequestForm($this->grav, $service);
        $slots = $form->slots();
        $state = ['values' => [], 'errors' => [], 'notice' => null, 'status' => null];

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $result = $form->handle(
                $_POST,
                is_string($_SERVER['HTTP_ORIGIN'] ?? null) ? $_SERVER['HTTP_ORIGIN'] : null,
                (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')
            );

            // Erfolg (und erkannte Bots): Post/Redirect/Get ohne Formulardaten in der URL
            if (in_array($result['status'], ['ok', 'spam'], true)) {
                $this->grav->redirect($service->url(Service::FORM_ROUTE . '/danke'), 303);
            }

            $state = [
                'values' => $result['values'],
                'errors' => $result['errors'],
                'status' => $result['status'],
                'notice' => match ($result['status']) {
                    'csrf'      => 'Das Formular war zu lange geöffnet oder die Sitzung ist abgelaufen. Bitte prüft eure Angaben und sendet das Formular noch einmal ab.',
                    'origin'    => 'Die Anfrage konnte nicht zugeordnet werden. Bitte ladet die Seite neu und versucht es noch einmal.',
                    'ratelimit' => 'Von eurem Anschluss kamen gerade sehr viele Anfragen. Bitte versucht es in einer Stunde noch einmal oder schreibt uns eine E-Mail.',
                    'error'     => 'Eure Anfrage konnte gerade nicht gespeichert werden. Bitte versucht es später noch einmal oder schreibt uns eine E-Mail.',
                    default     => null,
                },
            ];

            $code = match ($result['status']) {
                'ratelimit' => 429,
                'error'     => 503,
                default     => 422,
            };
            $page->modifyHeader('http_response_code', $code);
            $page->modifyHeader('cache_enable', false);
        } else {
            // Vorauswahl aus einem Link „Diesen Termin anfragen“
            $wanted = is_string($_GET['termin'] ?? null) ? $_GET['termin'] : '';

            if (isset($slots[$wanted])) {
                $state['values']['slot'] = $wanted;
            }
        }

        $state['slots']    = $slots;
        $state['csrf']     = $form->nonce();
        $state['timer']    = $form->timer();
        $state['tomorrow'] = date('Y-m-d', strtotime('+1 day'));

        $this->formState = $state;
    }

    public function onTwigInitialized(): void
    {
        TwigHelpers::register($this->grav['twig']->twig(), $this->grav);
    }

    public function onTwigSiteVariables(): void
    {
        $twig    = $this->grav['twig'];
        $service = Service::instance($this->grav);
        $page    = $this->grav['page'] ?? null;

        $twig->twig_vars['kneipe']      = $service;
        $twig->twig_vars['kneipe_form'] = $this->formState;
        $twig->twig_vars['kneipe_preview'] = $page instanceof PageInterface && $service->isPreview((string)$page->route());

        if ($page instanceof PageInterface && $page->template() === 'events') {
            $twig->twig_vars['calendar'] = CalendarView::build($service, (string)$page->url(), $_GET);
        }
    }

    // ------------------------------------------------------------------

    private function requestPath(): string
    {
        $path = (string)$this->grav['uri']->path();
        $base = rtrim((string)$this->grav['uri']->rootUrl(false), '/');

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $ext = (string)$this->grav['uri']->extension();

        if ($ext !== '' && !str_ends_with($path, '.' . $ext)) {
            $path .= '.' . $ext;
        }

        return '/' . ltrim($path, '/');
    }

    private function respond(string $body, string $type, array $headers = [], int $code = 200): never
    {
        $this->grav->close(new Response($code, ['Content-Type' => $type, 'X-Robots-Tag' => 'noindex'] + $headers, $body));

        exit;
    }

    private function notFound(): never
    {
        $this->respond("Nicht gefunden\n", 'text/plain; charset=utf-8', ['Cache-Control' => 'no-store'], 404);
    }
}
