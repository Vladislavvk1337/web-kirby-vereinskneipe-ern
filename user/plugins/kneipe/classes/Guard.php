<?php

namespace Grav\Plugin\Kneipe;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Yaml;
use RocketTheme\Toolbox\Event\Event;

/**
 * Setzt den Freigabeworkflow serverseitig durch – für jede Änderung über
 * Admin2 bzw. die REST-API (einzeln, per Stapelverarbeitung, Kopie,
 * Verschieben, Löschen).
 *
 * Rollen:
 *   admin     – Konto mit api.super, admin.super oder kneipe.admin
 *               (Gruppe „administration“) sowie Systemaufrufe ohne Konto
 *               (CLI, Anfrageformular)
 *   moderator – alle anderen angemeldeten Konten (Gruppe „moderation“)
 *
 * Die Seitenrechte im Frontmatter (permissions:) und die Gruppenrechte
 * (user/config/groups.yaml) regeln, was der Admin anbietet. Diese Klasse
 * prüft zusätzlich die fachlichen Regeln (Workflow.php).
 */
final class Guard
{
    /** Templates mit Änderungsprotokoll und Metadaten */
    public const TRACKED = ['event', 'team', 'article'];

    /** Templates, die Moderatoren anlegen, ändern, kopieren und verschieben dürfen */
    public const EDITABLE = [
        'event'   => Service::EVENTS_ROUTE,
        'team'    => Service::TEAMS_ROUTE,
        'article' => Service::NEWS_ROUTE,
    ];

    /** Felder, die nur das System schreibt */
    public const META = ['createdby', 'createdat', 'modifiedby', 'modifiedat', 'changelog'];

    /** Felder einer Anfrage, die die Redaktion bearbeiten darf */
    public const REQUEST_EDITABLE = ['processing', 'handledby', 'processingnote'];

    public function __construct(private Grav $grav, private Service $service)
    {
    }

    // ------------------------------------------------------------------
    // Rollen
    // ------------------------------------------------------------------

    public function user(): ?UserInterface
    {
        $user = $this->grav['user'] ?? null;

        return $user instanceof UserInterface && $user->exists() ? $user : null;
    }

    public function role(): string
    {
        return self::roleOf($this->user());
    }

    public static function roleOf(?UserInterface $user): string
    {
        // Ohne Konto: CLI, Anfrageformular, Tests
        if ($user === null) {
            return 'admin';
        }

        foreach (['api.super', 'admin.super', 'kneipe.admin'] as $permission) {
            if ($user->authorize($permission) === true) {
                return 'admin';
            }
        }

        return 'moderator';
    }

    public function actor(): string
    {
        $user = $this->user();

        if ($user === null) {
            return 'System';
        }

        $name = trim((string)($user->get('fullname') ?? ''));

        return $name !== '' ? $name . ' (' . $user->username . ')' : (string)$user->username;
    }

    // ------------------------------------------------------------------
    // Speichern (Anlegen, Ändern, Stapel-Veröffentlichen)
    // ------------------------------------------------------------------

    /**
     * onAdminSave: wird von der API vor jedem Speichern ausgelöst – beim
     * Anlegen, Ändern und beim Veröffentlichen per Stapelverarbeitung.
     */
    public function onAdminSave(Event $event): void
    {
        $page = $event['object'] ?? $event['page'] ?? null;

        if (!$page instanceof PageInterface) {
            return;
        }

        $stored      = $this->stored($page);
        $isNew       = $stored === null;
        $old         = $stored['header'] ?? [];
        $oldTemplate = $stored['template'] ?? null;
        $new         = (array)$page->header();
        $template    = (string)$page->template();
        $role        = $this->role();
        $privileged  = Workflow::isPrivileged($role);

        // Template wechseln verändert Rechte und Darstellung
        if ($isNew === false && $oldTemplate !== null && $oldTemplate !== $template && $privileged === false) {
            $this->deny('Die Seitenart ändert nur die Administration.');
        }

        // Seitenrechte im Frontmatter
        if ($privileged === false && $this->changed($old['permissions'] ?? null, $new['permissions'] ?? null)) {
            $this->deny('Seitenrechte ändert nur die Administration.');
        }

        // Anfragen: nie veröffentlichen, Formularangaben unverändert lassen
        if ($template === 'request' || $oldTemplate === 'request') {
            $new = $this->guardRequest($page, $isNew, $old, $new, $privileged);
            $this->writeHeader($page, $new);

            return;
        }

        // Alles außer Terminen, Teams und Meldungen: nur Administration
        if (isset(self::EDITABLE[$template]) === false && $privileged === false) {
            $this->deny('Diese Seite darf nur die Administration bearbeiten.');
        }

        if ($privileged === false && $isNew && $this->parentRoute($page) !== self::EDITABLE[$template]) {
            $this->deny('Neue Seiten dieser Art gehören unter ' . self::EDITABLE[$template] . '.');
        }

        if ($template === 'event') {
            $new = $this->guardEvent($page, $isNew, $old, $new, $role);
        }

        if (in_array($template, self::TRACKED, true)) {
            $new = $this->withMeta($template, $isNew, $old, $new);
        }

        $this->writeHeader($page, $new);
        $this->service->flush();
    }

    /** Regeln für Termine; liefert das (ggf. ergänzte) neue Frontmatter */
    private function guardEvent(PageInterface $page, bool $isNew, array $old, array $new, string $role): array
    {
        $approval     = $this->service->approvalMode();
        $privileged   = Workflow::isPrivileged($role);
        $from         = $isNew ? '' : (string)($old['orgstatus'] ?? 'frei');
        $to           = (string)($new['orgstatus'] ?? 'frei');
        $wasPublished = $isNew ? false : HeaderAccess::truthy($old['published'] ?? true);
        $isPublished  = HeaderAccess::truthy($new['published'] ?? true);

        if (Workflow::canSetOrgStatus($role, $approval, $from, $to) === false) {
            $this->deny('Im Freigabemodus dürfen nur Administratoren Termine bestätigen oder veröffentlichen. Bitte setzt den Status auf „zur Freigabe“.');
        }

        if ($wasPublished !== $isPublished && Workflow::canChangePublicationStatus($role, $approval) === false) {
            if ($isNew) {
                // Neue Termine der Moderation starten immer unveröffentlicht
                $new['published'] = false;
                $isPublished      = false;
                $page->published(false);
            } else {
                $this->deny('Im Freigabemodus veröffentlicht die Administration. Bitte setzt den Termin auf „zur Freigabe“.');
            }
        }

        $acceptedBefore = HeaderAccess::truthy($old['conflictaccepted'] ?? false);
        $accepted       = HeaderAccess::truthy($new['conflictaccepted'] ?? false);

        if ($accepted !== $acceptedBefore && $privileged === false) {
            $this->deny('Überschneidungen darf nur die Administration zulassen.');
        }

        // Beim Bestätigen/Veröffentlichen darf keine Überschneidung unbemerkt
        // bleiben – geprüft mit den neuen Werten.
        $relevant = in_array($to, EventStatus::APPROVAL_REQUIRED, true) || $isPublished;

        if ($relevant && EventStatus::isActive($to)) {
            $entry = new EventEntry($page, $this->service);

            if ($entry->timestampFrom('start', $new) === null) {
                $this->deny('Bitte zuerst den Beginn des Termins eintragen.');
            }

            $conflicts = $entry->conflicts($new);

            if (Workflow::canPublishWithConflicts(count($conflicts), $accepted) === false) {
                $this->deny('Doppelbelegung: ' . $entry->conflictInfo($new) . ' Veröffentlichen und Bestätigen sind gesperrt, bis die Überschneidung gelöst oder von der Administration zugelassen ist.');
            }
        }

        // Freigegebene Termine erhalten beim Veröffentlichen den passenden Status
        if ($isPublished && $wasPublished === false && in_array($to, ['freigabe', 'bestaetigt'], true)) {
            $new['orgstatus'] = 'veroeffentlicht';
        }

        return $new;
    }

    /** Regeln für Anfragen */
    private function guardRequest(PageInterface $page, bool $isNew, array $old, array $new, bool $privileged): array
    {
        if ($isNew && $privileged === false) {
            $this->deny('Anfragen entstehen nur über das öffentliche Formular.');
        }

        if ($isNew === false) {
            // Formularangaben und Metadaten bleiben, wie sie eingegangen sind
            $kept = $old;

            foreach (self::REQUEST_EDITABLE as $key) {
                if (array_key_exists($key, $new)) {
                    $kept[$key] = $new[$key];
                } else {
                    unset($kept[$key]);
                }
            }

            if ($privileged && array_key_exists('permissions', $new)) {
                $kept['permissions'] = $new['permissions'];
            }

            $new = $kept;
        }

        // Anfragen sind nie öffentlich
        $new['published'] = false;
        $new['routable']  = false;
        $new['visible']   = false;
        $page->published(false);
        $page->routable(false);
        $page->visible(false);

        return $new;
    }

    /** Metadaten und Änderungsprotokoll ergänzen */
    private function withMeta(string $template, bool $isNew, array $old, array $new): array
    {
        $now   = date('Y-m-d H:i:s');
        $actor = $this->actor();
        $note  = trim(is_scalar($new['changenote'] ?? null) ? (string)$new['changenote'] : '');

        // System-Felder nie aus der Eingabe übernehmen
        foreach (self::META as $key) {
            if (array_key_exists($key, $old)) {
                $new[$key] = $old[$key];
            } else {
                unset($new[$key]);
            }
        }

        unset($new['changenote']);

        if ($isNew) {
            $new['createdby'] = $actor;
            $new['createdat'] = $now;
            $new['uuid']      = $new['uuid'] ?? bin2hex(random_bytes(8));
        }

        $new['modifiedby'] = $actor;
        $new['modifiedat'] = $now;

        if ($template === 'event') {
            $changed = Changelog::changedFields($old, $new);

            if ($isNew || $changed !== [] || $note !== '') {
                $entries          = is_array($old['changelog'] ?? null) ? $old['changelog'] : [];
                $new['changelog'] = Changelog::prepend($entries, [
                    'date'    => $now,
                    'user'    => $actor,
                    'changes' => $isNew ? 'angelegt' : ($changed === [] ? '–' : 'Felder: ' . implode(', ', $changed)),
                    'note'    => $note,
                    'status'  => HeaderAccess::truthy($new['published'] ?? true) ? 'veröffentlicht' : 'unveröffentlicht',
                ]);
            }
        }

        return $new;
    }

    // ------------------------------------------------------------------
    // Löschen, Kopieren, Verschieben, Sortieren
    // ------------------------------------------------------------------

    public function onApiBeforePageDelete(Event $event): void
    {
        $page = $event['page'] ?? null;

        if (!$page instanceof PageInterface) {
            return;
        }

        $role     = $this->role();
        $template = (string)$page->template();

        if (Workflow::isPrivileged($role)) {
            return;
        }

        if ($template === 'event') {
            $published = (new EventEntry($page, $this->service))->isPublished();

            if (Workflow::canDeleteEvent($role, $published === false) === false) {
                $this->deny('Veröffentlichte Termine kann nur die Administration löschen. Tipp: Status „abgesagt“ oder „archiviert“ setzen.');
            }

            return;
        }

        if ($template === 'request') {
            $this->deny('Anfragen kann nur die Administration löschen. Sie werden nach Ablauf der Löschfrist automatisch entfernt.');
        }

        if (isset(self::EDITABLE[$template]) === false) {
            $this->deny('Diese Seite darf nur die Administration löschen.');
        }
    }

    /**
     * Kopien (einzeln oder per Stapel) entstehen ohne onAdminSave. Kopiert
     * die Moderation einen Termin, startet die Kopie unveröffentlicht und
     * ohne Freigabestatus; andere Seitenarten darf sie nicht kopieren.
     */
    public function onApiPageCreated(Event $event): void
    {
        $page = $event['page'] ?? null;

        if (!$page instanceof PageInterface || !isset($event['source_route'])) {
            $this->service->flush();

            return;
        }

        $template = (string)$page->template();

        if (Workflow::isPrivileged($this->role()) === false) {
            if (isset(self::EDITABLE[$template]) === false || $this->parentRoute($page) !== self::EDITABLE[$template]) {
                Folder::delete((string)$page->path());
                $this->deny('Diese Seite darf nur die Administration kopieren.');
            }
        }

        if (in_array($template, self::TRACKED, true)) {
            $header = (array)$page->header();

            foreach (self::META as $key) {
                unset($header[$key]);
            }

            unset($header['uuid']);

            if ($template === 'event' && Workflow::isPrivileged($this->role()) === false && $this->service->approvalMode()) {
                $header['published'] = false;

                if (in_array((string)($header['orgstatus'] ?? ''), [...EventStatus::APPROVAL_REQUIRED, 'freigabe'], true)) {
                    $header['orgstatus'] = 'reserviert';
                }

                $page->published(false);
            }

            $header = $this->withMeta($template, true, [], $header);
            $header['changelog'] = $template === 'event' ? [[
                'date'    => date('Y-m-d H:i:s'),
                'user'    => $this->actor(),
                'changes' => 'Kopie von ' . (string)$event['source_route'],
                'note'    => '',
                'status'  => HeaderAccess::truthy($header['published'] ?? true) ? 'veröffentlicht' : 'unveröffentlicht',
            ]] : ($header['changelog'] ?? null);

            if ($header['changelog'] === null) {
                unset($header['changelog']);
            }

            $this->writeHeader($page, $header);
            $page->save();
        }

        $this->service->flush();
    }

    /** Verschieben: Termine, Teams, Meldungen bleiben in ihrem Bereich */
    public function onApiPageMoved(Event $event): void
    {
        $page = $event['page'] ?? null;
        $this->service->flush();

        if (!$page instanceof PageInterface || Workflow::isPrivileged($this->role())) {
            return;
        }

        $template = (string)$page->template();
        $allowed  = self::EDITABLE[$template] ?? null;

        if ($allowed !== null && $this->parentRoute($page) === $allowed) {
            return;
        }

        // zurück an den alten Ort
        $oldParent = $this->service->page(dirname((string)$event['old_route']) ?: '/');
        $oldPath   = $oldParent !== null ? $oldParent->path() . '/' . basename((string)$page->path()) : null;

        if ($oldPath !== null && !file_exists($oldPath)) {
            Folder::move((string)$page->path(), $oldPath);
        }

        $this->deny('Seiten dieser Art verschiebt nur die Administration.');
    }

    public function onApiBeforePagesReorder(Event $event): void
    {
        if (Workflow::isPrivileged($this->role()) === false) {
            $this->deny('Die Reihenfolge der Seiten ändert nur die Administration.');
        }
    }

    public function onApiBeforePagesReorganize(Event $event): void
    {
        if (Workflow::isPrivileged($this->role()) === false) {
            $this->deny('Die Seitenstruktur ändert nur die Administration.');
        }
    }

    public function onApiBeforePageTranslate(Event $event): void
    {
        if (Workflow::isPrivileged($this->role()) === false) {
            $this->deny('Übersetzungen legt nur die Administration an.');
        }
    }

    // ------------------------------------------------------------------
    // Hilfen
    // ------------------------------------------------------------------

    /**
     * Gespeicherter Stand einer Seite vor dieser Änderung – direkt von der
     * Festplatte gelesen (die Seite im Speicher ist bereits geändert).
     *
     * @return array{header: array, template: string}|null null = neue Seite
     */
    public function stored(PageInterface $page): ?array
    {
        $dir = (string)$page->path();

        if ($dir === '' || !is_dir($dir)) {
            return null;
        }

        $current = (string)$page->filePath();
        $file    = is_file($current) ? $current : null;

        if ($file === null) {
            // Template gewechselt: die alte Datei trägt noch den alten Namen
            $candidates = glob($dir . '/*.md') ?: [];
            $file       = $candidates[0] ?? null;
        }

        if ($file === null) {
            return null;
        }

        return [
            'header'   => self::parseFrontmatter((string)file_get_contents($file)),
            'template' => preg_replace('/(\.[a-z]{2})?\.md$/', '', basename($file)),
        ];
    }

    public static function parseFrontmatter(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        if (preg_match('/^---\R(.*?)\R---(\R|$)/s', $raw, $match) !== 1) {
            return [];
        }

        try {
            $header = Yaml::parse($match[1]);
        } catch (\Throwable) {
            return [];
        }

        return is_array($header) ? $header : [];
    }

    private function writeHeader(PageInterface $page, array $header): void
    {
        $page->header((object)$header);
    }

    private function parentRoute(PageInterface $page): string
    {
        $parent = $page->parent();

        if ($parent !== null && $parent->route() !== null && !$parent->root()) {
            return $this->service->normalizeRoute((string)$parent->route());
        }

        // Neue Seite: Elternordner über den Pfad bestimmen
        $pagesRoot = rtrim((string)$this->grav['locator']->findResource('page://', true), '/');
        $parentDir = dirname((string)$page->path());

        if ($parentDir === $pagesRoot) {
            return '/';
        }

        $folder = basename($parentDir);

        return '/' . (preg_replace('/^\d+\./', '', $folder) ?? $folder);
    }

    private function changed(mixed $a, mixed $b): bool
    {
        return json_encode($a) !== json_encode($b);
    }

    private function deny(string $message): never
    {
        if (class_exists(\Grav\Plugin\Api\Exceptions\ForbiddenException::class)) {
            throw new \Grav\Plugin\Api\Exceptions\ForbiddenException($message);
        }

        throw new \RuntimeException($message, 403);
    }
}
