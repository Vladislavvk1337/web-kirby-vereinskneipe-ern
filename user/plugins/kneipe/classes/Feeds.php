<?php

namespace Grav\Plugin\Kneipe;

use Grav\Common\Page\Interfaces\PageInterface;

/**
 * Maschinenlesbare Ausgaben: iCalendar, XML-Sitemap, robots.txt.
 */
final class Feeds
{
    /** Templates, die nie in der Sitemap erscheinen */
    public const SITEMAP_EXCLUDE = ['error', 'confirmation', 'styleguide', 'requests', 'request', 'settings'];

    public function __construct(private Service $service)
    {
    }

    public function prodId(): string
    {
        return '-//' . $this->service->host() . '//Termine//DE';
    }

    /**
     * Abonnierbarer Feed: veröffentlichte, bestätigte und abgesagte Termine
     * (letzte 90 Tage und alle kommenden). Freie Termine und geschlossene
     * Tage nicht.
     */
    public function icsFeed(?int $now = null): string
    {
        $now    = $now ?? time();
        $since  = $now - 90 * 86400;
        $events = [];

        foreach ($this->service->publicEvents() as $event) {
            if ($event->hasIcs() && $event->endTimestamp() >= $since) {
                $events[] = $event->icsData();
            }
        }

        return Ics::calendar($events, $this->service->name(), $this->prodId(), $now);
    }

    public function icsEvent(EventEntry $event, ?int $now = null): ?string
    {
        if ($event->hasIcs() === false) {
            return null;
        }

        return Ics::calendar([$event->icsData()], $this->service->name(), $this->prodId(), $now);
    }

    public function robots(): string
    {
        $lines = ['User-agent: *'];

        if ($this->service->noindex()) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Disallow: /admin';
            $lines[] = 'Disallow: /api';
            $lines[] = 'Disallow: /termin-anfragen/danke';
            $lines[] = 'Disallow: /bausteine';
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $this->service->absoluteUrl('/sitemap.xml');
        }

        return implode("\n", $lines) . "\n";
    }

    public function sitemap(): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

        foreach ($this->sitemapPages() as $page) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars($this->service->absoluteUrl((string)$page->url()), ENT_XML1) . '</loc>';
            $xml[] = '    <lastmod>' . date('c', (int)$page->modified()) . '</lastmod>';
            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return implode("\n", $xml) . "\n";
    }

    /** @return PageInterface[] */
    public function sitemapPages(): array
    {
        $pages = [];

        foreach ($this->service->pages()->all() as $page) {
            /** @var PageInterface $page */
            $template = (string)$page->template();

            if ($page->published() === false || $page->routable() === false || in_array($template, self::SITEMAP_EXCLUDE, true)) {
                continue;
            }

            if ($template === 'event' && $this->service->event($page)->isPublic() === false) {
                continue;
            }

            if ($template === 'team' && $this->service->teamFor($page)->isPublic() === false) {
                continue;
            }

            if (HeaderAccess::truthy(((array)$page->header())['noindex'] ?? false)) {
                continue;
            }

            $pages[] = $page;
        }

        return $pages;
    }
}
