<?php

/**
 * Projekt-Plugin „kneipe“: Terminlogik, Freigabeworkflow, Anfrageformular,
 * iCalendar, Sitemap/robots.txt und das Panel-Dashboard.
 *
 * Reine Logik steckt in src/ (ohne Kirby testbar), die Verbindung zu
 * Kirby in hooks.php, routes.php und blueprints.php.
 */

use Kirby\Cms\App as Kirby;
use Kneipe\Richtext;
use Kneipe\Service;

load([
	'Kneipe\\Calendar'         => 'src/Calendar.php',
	'Kneipe\\Changelog'        => 'src/Changelog.php',
	'Kneipe\\EventStatus'      => 'src/EventStatus.php',
	'Kneipe\\FormTimer'        => 'src/FormTimer.php',
	'Kneipe\\Ics'              => 'src/Ics.php',
	'Kneipe\\Overlap'          => 'src/Overlap.php',
	'Kneipe\\RateLimiter'      => 'src/RateLimiter.php',
	'Kneipe\\RequestForm'      => 'src/RequestForm.php',
	'Kneipe\\RequestValidator' => 'src/RequestValidator.php',
	'Kneipe\\Retention'        => 'src/Retention.php',
	'Kneipe\\Richtext'         => 'src/Richtext.php',
	'Kneipe\\Service'          => 'src/Service.php',
	'Kneipe\\Workflow'         => 'src/Workflow.php',
], __DIR__);

require_once __DIR__ . '/helpers.php';

if (function_exists('kneipe') === false) {
	function kneipe(): Service
	{
		return Service::instance();
	}
}

Kirby::plugin('kneipe/core', [
	'blueprints' => require __DIR__ . '/blueprints.php',
	'hooks'      => require __DIR__ . '/hooks.php',
	'routes'     => require __DIR__ . '/routes.php',

	'fieldMethods' => [
		/**
		 * Writer-Inhalte sicher ausgeben (zweite Sicherung nach Kirbys
		 * Bereinigung beim Speichern)
		 */
		'toSafeHtml' => fn ($field) => kneipe_mark(Richtext::sanitize((string)$field->value())),
		/** Einfachen Text maskiert ausgeben, Platzhalter hervorheben */
		'toSafeText' => fn ($field) => kneipe_text($field->value()),
	],

	'siteMethods' => [
		'kneipe' => fn () => kneipe(),
		// Abfragen für das Panel-Dashboard (site.yml)
		'dashboardNext'        => fn () => kneipe()->nextConfirmedEvents(),
		'dashboardRequests'    => fn () => kneipe()->openRequests(),
		'dashboardApproval'    => fn () => kneipe()->eventsAwaitingApproval(),
		'dashboardFree'        => fn () => kneipe()->panelFreeEvents(),
		'dashboardIncomplete'  => fn () => kneipe()->incompleteEvents(),
		'dashboardConflicts'   => fn () => kneipe()->conflictingEvents(),
		'dashboardTeams'       => fn () => kneipe()->currentTeams(),
		'dashboardRecent'      => fn () => kneipe()->recentlyModified(),
		'dashboardWarnings'    => fn () => kneipe()->setupWarningsText(),
		'dashboardApprovalMode' => fn () => kneipe()->approvalMode()
			? 'Freigabemodus ist aktiv: Moderatoren bereiten Termine vor und setzen sie auf „zur Freigabe“. Veröffentlichen und Bestätigen erfolgt durch die Administration.'
			: 'Freigabemodus ist aus: Moderatoren dürfen Termine selbst veröffentlichen.',
	],

	'tags' => [
		/**
		 * (stammdaten: street) – Wert aus den Stammdaten im Panel oder ein
		 * deutlich markierter Platzhalter, wenn er noch fehlt.
		 */
		'stammdaten' => [
			'html' => function ($tag) {
				$site  = $tag->kirby()->site();
				$key   = strtolower(trim($tag->value));
				$label = [
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
				][$key] ?? $key;

				$value = match ($key) {
					'name'           => $site->title()->value(),
					'address'        => kneipe()->addressLine(),
					'retention'      => (string)kneipe()->retentionDays(),
					'representative' => $site->legalrepresentative()->value(),
					'register'       => $site->legalregister()->value(),
					'responsible'    => $site->legalresponsible()->value(),
					'hoster'         => $site->legalhoster()->value(),
					'mailprovider'   => $site->legalmailprovider()->value(),
					default          => $site->content()->get($key)->value(),
				};

				if ($value === null || trim($value) === '') {
					return '<mark class="placeholder">[' . htmlspecialchars($label) . ' – noch eintragen]</mark>';
				}

				if ($key === 'email') {
					return Html::email($value);
				}

				if ($key === 'phone') {
					return Html::tel($value);
				}

				return nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
			},
		],
	],
]);
