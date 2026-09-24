<?php

/**
 * Hooks: Freigabeworkflow, Überschneidungsprüfung, Metadaten und
 * Änderungsprotokoll.
 *
 * Die Blueprint-Optionen und Rollenrechte regeln, was im Panel angeboten
 * wird. Diese Hooks prüfen zusätzlich serverseitig – auch für Aufrufe
 * über die REST-API –, damit Moderatoren im Freigabemodus nichts
 * veröffentlichen oder bestätigen können.
 */

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Exception\PermissionException;
use Kneipe\Changelog;
use Kneipe\EventStatus;
use Kneipe\Service;
use Kneipe\Workflow;

$role = function (): string|null {
	$user = App::instance()->user();

	if ($user === null) {
		return null;
	}

	// Kirbys interner Systembenutzer (Formular, CLI, Tests mit impersonate)
	if ($user->id() === 'kirby') {
		return 'admin';
	}

	return $user->role()->id();
};

$actor = function (): string {
	$user = App::instance()->user();

	if ($user === null || $user->id() === 'kirby') {
		return 'System';
	}

	return trim($user->name()->value() . ' <' . $user->email() . '>');
};

$tracked = ['event', 'team', 'article'];

/** Metadaten und Protokoll speichern, ohne weitere Hooks auszulösen */
$writeMeta = function (Page $page, array $data): Page {
	return App::instance()->impersonate('kirby', fn () => $page->save($data));
};

$logEntry = function (Page $page, string $what, string $note = '') use ($actor, $writeMeta): Page {
	$entries = $page->content()->get('changelog')->yaml();
	$entries = Changelog::prepend($entries, [
		'date'    => date('Y-m-d H:i:s'),
		'user'    => $actor(),
		'changes' => $what,
		'note'    => $note,
		'status'  => $page->isDraft() ? 'Entwurf' : 'veröffentlicht',
	]);

	return $writeMeta($page, ['changelog' => $entries]);
};

/**
 * Bestätigen/Veröffentlichen nur ohne ungelöste Überschneidung.
 * $data enthält die neuen, noch nicht gespeicherten Werte.
 */
$assertPublishable = function (Page $page, array $data, bool $accepted): void {
	if ($page->timestampFrom('start', $data) === null) {
		throw new PermissionException(message: 'Bitte zuerst den Beginn des Termins eintragen.');
	}

	if (Workflow::canPublishWithConflicts($page->conflicts($data)->count(), $accepted) === false) {
		throw new PermissionException(message: 'Doppelbelegung: ' . $page->conflictInfo($data));
	}
};

return [
	// Platzhalter in KirbyText (Rechtstexte) sichtbar markieren
	'kirbytext:after' => fn (string $text) => kneipe_mark($text),

	// ---------------------------------------------------------------------
	// Termine: organisatorischen Status und Überschneidungen prüfen
	// ---------------------------------------------------------------------
	'page.update:before' => function (Page $page, array $values, array $strings) use ($role, $assertPublishable) {
		$template = $page->intendedTemplate()->name();

		if (in_array($template, ['legal', 'home', 'about', 'join', 'contact', 'requestform', 'events', 'teams', 'news'], true) === true && Workflow::isPrivileged($role()) === false) {
			throw new PermissionException(message: 'Diese Seite darf nur die Administration bearbeiten.');
		}

		if ($template !== 'event') {
			return;
		}

		$from     = $page->orgStatus();
		$to       = $strings['orgstatus'] ?? $from;
		$approval = kneipe()->approvalMode();

		if (Workflow::canSetOrgStatus($role(), $approval, $from, $to) === false) {
			throw new PermissionException(
				message: 'Im Freigabemodus dürfen nur Administratoren Termine bestätigen oder veröffentlichen. Bitte setzt den Status auf „zur Freigabe“.'
			);
		}

		$acceptedBefore = $page->content()->get('conflictaccepted')->toBool();
		$accepted       = array_key_exists('conflictaccepted', $strings)
			? in_array(strtolower((string)$strings['conflictaccepted']), ['true', '1'], true)
			: $acceptedBefore;

		if ($accepted !== $acceptedBefore && Workflow::isPrivileged($role()) === false) {
			throw new PermissionException(message: 'Überschneidungen darf nur die Administration zulassen.');
		}

		// Beim Bestätigen/Veröffentlichen darf keine Überschneidung unbemerkt
		// bleiben. Geprüft wird mit den neuen Zeiten.
		$relevant = in_array($to, EventStatus::APPROVAL_REQUIRED, true) === true || $page->isDraft() === false;

		if ($relevant === true && EventStatus::isActive($to) === true) {
			$assertPublishable($page, $strings, $accepted);
		}
	},

	'page.update:after' => function (Page $newPage, Page $oldPage) use ($tracked, $actor, $writeMeta, $logEntry) {
		Service::flush();

		if (in_array($newPage->intendedTemplate()->name(), $tracked, true) === false) {
			return;
		}

		$note    = trim((string)$newPage->content()->get('changenote')->value());
		$changed = Changelog::changedFields($oldPage->content()->toArray(), $newPage->content()->toArray());
		$page    = $writeMeta($newPage, [
			'modifiedby' => $actor(),
			'modifiedat' => date('Y-m-d H:i:s'),
			'changenote' => '',
		]);

		if ($newPage->intendedTemplate()->name() === 'event' && ($changed !== [] || $note !== '')) {
			$logEntry($page, $changed === [] ? '–' : 'Felder: ' . implode(', ', $changed), $note);
		}
	},

	'page.create:after' => function (Page $page) use ($tracked, $actor, $writeMeta) {
		Service::flush();

		if (in_array($page->intendedTemplate()->name(), $tracked, true) === false) {
			return;
		}

		$writeMeta($page, [
			'createdby'  => $actor(),
			'createdat'  => date('Y-m-d H:i:s'),
			'modifiedby' => $actor(),
			'modifiedat' => date('Y-m-d H:i:s'),
		]);
	},

	// ---------------------------------------------------------------------
	// Veröffentlichen: Freigabemodus und Doppelbelegung
	// ---------------------------------------------------------------------
	'page.changeStatus:before' => function (Page $page, string $status) use ($role, $assertPublishable) {
		$template = $page->intendedTemplate()->name();

		if ($template === 'request') {
			throw new PermissionException(message: 'Anfragen werden nie veröffentlicht.');
		}

		if ($template !== 'event') {
			return;
		}

		if (Workflow::canChangePublicationStatus($role(), kneipe()->approvalMode()) === false) {
			throw new PermissionException(
				message: 'Im Freigabemodus veröffentlicht die Administration. Bitte setzt den Termin auf „zur Freigabe“.'
			);
		}

		if ($status !== 'draft') {
			$assertPublishable($page, [], $page->content()->get('conflictaccepted')->toBool());
		}
	},

	'page.changeStatus:after' => function (Page $newPage, Page $oldPage) use ($logEntry, $writeMeta) {
		Service::flush();

		if ($newPage->intendedTemplate()->name() !== 'event') {
			return;
		}

		$page = $newPage;

		// Freigegebene Termine erhalten beim Veröffentlichen den passenden Status
		if ($newPage->isDraft() === false && in_array($newPage->orgStatus(), ['freigabe', 'bestaetigt'], true) === true) {
			$page = $writeMeta($newPage, ['orgstatus' => 'veroeffentlicht']);
		}

		$logEntry($page, 'Veröffentlichung: ' . ($newPage->isDraft() ? 'zurück zum Entwurf' : 'veröffentlicht'));
	},

	'page.delete:before' => function (Page $page) use ($role) {
		$template = $page->intendedTemplate()->name();

		if ($template === 'event' && Workflow::canDeleteEvent($role(), $page->isDraft()) === false) {
			throw new PermissionException(message: 'Veröffentlichte Termine kann nur die Administration löschen. Tipp: Status „abgesagt“ oder „archiviert“ setzen.');
		}

		if ($template === 'request' && Workflow::isPrivileged($role()) === false) {
			throw new PermissionException(message: 'Anfragen kann nur die Administration löschen.');
		}
	},

	'page.delete:after' => fn () => Service::flush(),

	'page.create:before' => function (Page $page) use ($role) {
		if ($page->intendedTemplate()->name() === 'request' && Workflow::isPrivileged($role()) === false) {
			throw new PermissionException(message: 'Anfragen entstehen nur über das öffentliche Formular.');
		}
	},

	// ---------------------------------------------------------------------
	// Benutzer und Einstellungen: nur Administration
	// ---------------------------------------------------------------------
	'user.create:before' => function (User $user) use ($role) {
		if (Workflow::isPrivileged($role()) === false) {
			throw new PermissionException(message: 'Benutzerkonten legt nur die Administration an.');
		}
	},

	'user.changeRole:before' => function (User $user) use ($role) {
		if (Workflow::isPrivileged($role()) === false) {
			throw new PermissionException(message: 'Rollen ändert nur die Administration.');
		}
	},

	'user.delete:before' => function (User $user) use ($role) {
		if (Workflow::isPrivileged($role()) === false) {
			throw new PermissionException(message: 'Benutzerkonten löscht nur die Administration.');
		}
	},

	'site.update:before' => function () use ($role) {
		if (Workflow::isPrivileged($role()) === false) {
			throw new PermissionException(message: 'Stammdaten und Einstellungen pflegt nur die Administration.');
		}
	},
];
