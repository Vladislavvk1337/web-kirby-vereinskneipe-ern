<?php

use Kirby\Exception\PermissionException;

require_once dirname(__DIR__) . '/support/kirby.php';

const MOD = 'moderation@example.org';
const ADMIN = 'admin@example.org';

test('Moderation kann keine Benutzer anlegen und keine Rollen ändern', function () {
	$kirby = fresh_kirby();

	assert_throws(Throwable::class, fn () => as_user($kirby, MOD, fn () => $kirby->users()->create([
		'email' => 'neu@example.org', 'role' => 'admin', 'password' => 'test-passwort-123',
	])));

	assert_throws(Throwable::class, fn () => as_user($kirby, MOD, fn () => $kirby->user(MOD)->changeRole('admin')));
	assert_same('moderator', $kirby->users()->find(MOD)->role()->id());
	assert_same(null, $kirby->users()->find('neu@example.org'));
});

test('Moderation kann Stammdaten und Einstellungen nicht ändern', function () {
	$kirby = fresh_kirby();
	assert_throws(Throwable::class, fn () => as_user($kirby, MOD, fn () => $kirby->site()->update(['approvalmode' => 'false'])));
	assert_throws(Throwable::class, fn () => as_user($kirby, MOD, fn () => $kirby->site()->changeTitle('Neuer Name')));
	assert_true(kneipe()->approvalMode());

	// Administration darf
	as_user($kirby, ADMIN, fn () => $kirby->site()->update(['claim' => 'Neue Leitzeile']));
	assert_same('Neue Leitzeile', $kirby->site()->claim()->value());
});

test('Moderation kann Impressum und Datenschutz nicht verändern', function () {
	$kirby = fresh_kirby();

	foreach (['impressum', 'datenschutz'] as $slug) {
		assert_throws(Throwable::class, fn () => as_user($kirby, MOD, fn () => $kirby->page($slug)->update(['text' => 'geändert'])), $slug);
		assert_false($kirby->page($slug)->isListable() && false);
	}

	as_user($kirby, MOD, function () use ($kirby) {
		assert_false($kirby->page('impressum')->isAccessible(), 'Impressum im Panel nicht erreichbar');
		assert_false($kirby->page('datenschutz')->isListable());
	});

	as_user($kirby, ADMIN, fn () => $kirby->page('impressum')->update(['text' => 'Admin darf']));
	assert_same('Admin darf', $kirby->page('impressum')->text()->value());
});

test('Moderation kann im Freigabemodus nicht veröffentlichen', function () {
	$kirby = fresh_kirby();
	$draft = event($kirby, 'demo-termin-zur-freigabe');

	assert_throws(PermissionException::class, fn () => as_user($kirby, MOD, fn () => $draft->changeStatus('listed')));
	assert_true(event($kirby, 'demo-termin-zur-freigabe')->isDraft());
});

test('Moderation kann im Freigabemodus nicht bestätigen, aber zur Freigabe vorbereiten', function () {
	$kirby = fresh_kirby();
	$event = event($kirby, 'demo-reserviert');

	foreach (['bestaetigt', 'veroeffentlicht'] as $status) {
		assert_throws(PermissionException::class, fn () => as_user($kirby, MOD, fn () => $event->update(['orgstatus' => $status])), $status);
	}

	as_user($kirby, MOD, fn () => event($kirby, 'demo-reserviert')->update(['orgstatus' => 'freigabe', 'teaser' => 'Vorbereitet']));
	assert_same('freigabe', event($kirby, 'demo-reserviert')->orgStatus());
});

test('Moderation darf Überschneidungen nicht selbst zulassen', function () {
	$kirby = fresh_kirby();
	assert_throws(PermissionException::class, fn () => as_user($kirby, MOD, fn () => event($kirby, 'demo-doppelbelegung')->update(['conflictaccepted' => true])));
});

test('ohne Freigabemodus darf Moderation veröffentlichen', function () {
	$kirby = fresh_kirby();
	as_user($kirby, ADMIN, fn () => $kirby->site()->update(['approvalmode' => false]));
	$event = as_user($kirby, MOD, fn () => event($kirby, 'demo-termin-zur-freigabe')->changeStatus('listed'));
	assert_false(event($kirby, 'demo-termin-zur-freigabe')->isDraft());
});

test('Administration veröffentlicht: Status wird gesetzt und protokolliert', function () {
	$kirby = fresh_kirby();
	as_user($kirby, ADMIN, fn () => event($kirby, 'demo-termin-zur-freigabe')->changeStatus('listed'));

	$event = event($kirby, 'demo-termin-zur-freigabe');
	assert_false($event->isDraft());
	assert_same('veroeffentlicht', $event->orgStatus());
	$log = $event->changelog()->yaml();
	assert_contains('veröffentlicht', $log[0]['changes']);
	assert_contains('Test Admin', $log[0]['user']);
	assert_true($event->isPublic());
	assert_true(in_array($event->id(), kneipe()->upcomingEvents(null, false, false, TEST_NOW)->keys(), true), 'erscheint automatisch in der Terminliste');
});

test('Doppelbelegung verhindert Veröffentlichen – bis sie ausdrücklich zugelassen ist', function () {
	$kirby = fresh_kirby();
	$e = assert_throws(PermissionException::class, fn () => as_user($kirby, ADMIN, fn () => event($kirby, 'demo-doppelbelegung')->changeStatus('listed')));
	assert_contains('Doppelbelegung', $e->getMessage());

	// Bestätigen per Status ebenfalls gesperrt
	assert_throws(PermissionException::class, fn () => as_user($kirby, ADMIN, fn () => event($kirby, 'demo-doppelbelegung')->update(['orgstatus' => 'bestaetigt'])));

	// Verschieben auf eine freie Zeit löst die Überschneidung
	as_user($kirby, ADMIN, fn () => event($kirby, 'demo-doppelbelegung')->update(['start' => '2026-10-03 15:00:00', 'end' => '2026-10-03 17:00:00']));
	as_user($kirby, ADMIN, fn () => event($kirby, 'demo-doppelbelegung')->changeStatus('listed'));
	assert_false(event($kirby, 'demo-doppelbelegung')->isDraft());
});

test('Zugelassene Überschneidung erlaubt die Veröffentlichung', function () {
	$kirby = fresh_kirby();
	as_user($kirby, ADMIN, fn () => event($kirby, 'demo-doppelbelegung')->update(['conflictaccepted' => true]));
	as_user($kirby, ADMIN, fn () => event($kirby, 'demo-doppelbelegung')->changeStatus('listed'));
	assert_false(event($kirby, 'demo-doppelbelegung')->isDraft());
});

test('Moderation löscht nur Entwürfe', function () {
	$kirby = fresh_kirby();
	assert_throws(PermissionException::class, fn () => as_user($kirby, MOD, fn () => event($kirby, 'liederabend')->delete()));
	as_user($kirby, MOD, fn () => event($kirby, 'demo-reserviert')->delete());
	assert_same(null, $kirby->site()->find('termine')->findPageOrDraft('demo-reserviert'));
});

test('Neuer Termin der Moderation: Ersteller, Zeitpunkt und Protokoll', function () {
	$kirby = fresh_kirby();
	$page  = as_user($kirby, MOD, fn () => $kirby->site()->find('termine')->createChild([
		'slug' => 'test-termin', 'template' => 'event',
		'content' => ['title' => 'Testabend', 'start' => '2026-12-11 19:00:00', 'orgstatus' => 'angefragt', 'category' => 'thekenteam'],
	]));

	$page = event($kirby, 'test-termin');
	assert_true($page->isDraft(), 'neue Termine sind Entwürfe');
	assert_contains('Test Moderation', $page->createdby()->value());
	assert_true($page->createdat()->isNotEmpty());

	as_user($kirby, MOD, fn () => event($kirby, 'test-termin')->update(['teaser' => 'Neu', 'changenote' => 'Kurzbeschreibung ergänzt']));
	$page = event($kirby, 'test-termin');
	$log  = $page->changelog()->yaml();
	assert_contains('teaser', $log[0]['changes']);
	assert_same('Kurzbeschreibung ergänzt', $log[0]['note']);
	assert_same('', (string)$page->changenote()->value(), 'Notiz wird nach dem Speichern geleert');
	assert_contains('Test Moderation', $page->modifiedby()->value());
});
