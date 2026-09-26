<?php

namespace Grav\Plugin\Kneipe;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/kneipe/overview – Daten für die Redaktionsübersicht im Admin
 * (admin-next/pages/kneipe.js). Nur für angemeldete Konten mit
 * Leserecht auf Seiten; enthält keine Kontaktdaten aus Anfragen.
 */
class OverviewController extends AbstractApiController
{
    public function overview(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $service    = Service::instance($this->grav);
        $role       = Guard::roleOf($this->getUser($request));
        $event      = fn (EventEntry $e, string $extra = '') => [
            'title'  => $e->title(),
            'route'  => $e->id(),
            'when'   => $service->formatDateTime($e),
            'status' => $e->orgStatusLabel() . ($e->isPublished() ? '' : ', unveröffentlicht'),
            'note'   => $extra,
        ];
        $next = $service->nextOpening();

        return ApiResponse::create([
            'role'         => $role,
            'approvalMode' => $service->approvalMode(),
            'approvalText' => $service->approvalMode()
                ? 'Freigabemodus ist aktiv: Die Moderation bereitet Termine vor und setzt sie auf „zur Freigabe“. Bestätigen und Veröffentlichen erfolgt durch die Administration.'
                : 'Freigabemodus ist aus: Die Moderation darf Termine selbst veröffentlichen.',
            'next'         => $next !== null ? [$event($next, $next->publicTeam()?->title() ?? '')] : [],
            'requests'     => array_map(fn (PageInterface $p) => [
                'title'  => (string)$p->title(),
                'route'  => (string)$p->route(),
                'when'   => date('d.m.Y H:i', (int)strtotime((string)($p->header()->submittedat ?? ''))),
                'status' => (string)($p->header()->processing ?? 'neu'),
                'note'   => '',
            ], $service->openRequests()),
            'approval'     => array_map(fn (EventEntry $e) => $event($e), $service->eventsAwaitingApproval()),
            'conflicts'    => array_map(fn (EventEntry $e) => $event($e, $e->conflictInfo()), $service->conflictingEvents()),
            'incomplete'   => array_map(fn (EventEntry $e) => $event($e, 'Es fehlt: ' . implode(', ', $e->missingInfo())), $service->incompleteEvents()),
            'free'         => array_map(fn (EventEntry $e) => $event($e), $service->sortEvents(array_filter(
                $service->allEvents(),
                fn (EventEntry $e) => $e->orgStatus() === 'frei' && $e->isPast() === false
            ))),
            'warnings'     => $role === 'admin' ? $service->setupWarnings() : [],
            'retention'    => $service->retentionDays(),
        ]);
    }
}
