<?php

use Kirby\Email\Email;
use Kirby\Exception\PermissionException;
use Kneipe\RequestForm;
use Kneipe\Retention;

require_once dirname(__DIR__) . '/support/kirby.php';

$values = fn () => [
	'slot' => 'anderer', 'wishdate' => date('Y-m-d', strtotime('+40 days')), 'altdate' => '',
	'groupname' => 'Testgruppe', 'grouptype' => 'verein', 'contactname' => 'Test Person',
	'email' => 'test@example.org', 'phone' => '', 'intro' => 'Wir sind eine Testgruppe.',
	'message' => 'Geheime Nachricht XINTERNX', 'privacy' => 'ja',
];

test('Anfrage wird als unveröffentlichte Seite gespeichert – nie öffentlich', function () use ($values) {
	$kirby   = fresh_kirby();
	$request = (new RequestForm($kirby))->store($values());

	assert_true($request->isDraft());
	assert_same('request', $request->intendedTemplate()->name());
	assert_same('neu', $request->processing()->value());
	assert_same(0, $kirby->site()->find('anfragen')->children()->count(), 'keine veröffentlichten Anfragen');

	// nicht einmal die Administration kann eine Anfrage veröffentlichen
	// (Kirby lehnt den Status laut Blueprint ab, der Hook zusätzlich)
	assert_throws(Throwable::class, fn () => $kirby->impersonate('admin@example.org', fn () => $request->changeStatus('listed')));
	assert_true(in_array($request->id(), kneipe()->openRequests()->keys(), true), 'erscheint im Dashboard');
});

test('Anfragen: Moderation sieht sie, darf sie aber nicht löschen', function () use ($values) {
	$kirby   = fresh_kirby();
	$request = (new RequestForm($kirby))->store($values());

	as_user($kirby, 'moderation@example.org', fn () => assert_true($request->isAccessible()));
	assert_throws(PermissionException::class, fn () => as_user($kirby, 'moderation@example.org', fn () => $request->delete()));
	as_user($kirby, 'admin@example.org', fn () => $request->delete());
	assert_same(0, $kirby->site()->find('anfragen')->drafts()->count());
});

test('Benachrichtigung und Eingangsbestätigung ohne Formularinhalte in der Bestätigung', function () use ($values) {
	$kirby   = fresh_kirby();
	$form    = new RequestForm($kirby);
	$request = $form->store($values());
	Email::$emails = [];
	$form->notify($request, $values());

	assert_same(2, count(Email::$emails));
	[$notification, $receipt] = Email::$emails;
	assert_same(['redaktion@example.org'], array_keys($notification->to()));
	assert_same('test@example.org', $notification->replyTo());
	assert_contains('Testgruppe', $notification->body()->text());
	assert_contains('/panel/pages/anfragen+', $notification->body()->text());
	assert_not_contains('XINTERNX', $notification->body()->text(), 'Nachricht nur im Panel');
	assert_not_contains('Testgruppe', $receipt->body()->text(), 'Bestätigung ohne Eingaben (kein Spam-Relais)');
});

test('Löschfrist entfernt alte Anfragen', function () use ($values) {
	$kirby = fresh_kirby();
	$form  = new RequestForm($kirby);
	$old   = $form->store($values());
	$kirby->impersonate('kirby', fn () => $old->save(['submittedat' => date('Y-m-d H:i:s', strtotime('-200 days'))]));
	$form->store($values());

	assert_same(1, Retention::cleanup($kirby, 180));
	assert_same(1, $kirby->site()->find('anfragen')->drafts()->count());
});
