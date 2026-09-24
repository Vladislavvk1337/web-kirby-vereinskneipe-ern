<?php

namespace Kneipe;

/**
 * Terminarten, organisatorische Status und ihre öffentliche Darstellung.
 *
 * Der organisatorische Status (Feld „orgstatus“) ist unabhängig vom
 * Kirby-Veröffentlichungsstatus (Entwurf/veröffentlicht). Öffentlich
 * erscheint ein Termin nur, wenn er in Kirby veröffentlicht ist UND sein
 * organisatorischer Status eine öffentliche Entsprechung hat.
 */
final class EventStatus
{
	public const CATEGORIES = [
		'kneipenabend' => 'Regulärer Kneipenabend',
		'thekenteam'   => 'Thekenteam',
		'sonder'       => 'Sonderveranstaltung',
		'senioren'     => 'Seniorennachmittag',
		'kultur'       => 'Kultur oder Musik',
		'privat'       => 'Private oder interne Veranstaltung',
		'geschlossen'  => 'Geschlossen',
	];

	public const ORG = [
		'frei'            => 'frei',
		'angefragt'       => 'angefragt',
		'reserviert'      => 'reserviert',
		'freigabe'        => 'zur Freigabe',
		'bestaetigt'      => 'bestätigt',
		'veroeffentlicht' => 'veröffentlicht',
		'abgesagt'        => 'abgesagt',
		'geschlossen'     => 'geschlossen',
		'archiviert'      => 'archiviert',
	];

	/** Nur intern – solche Termine erscheinen nie öffentlich */
	public const INTERNAL = ['angefragt', 'reserviert', 'freigabe'];

	/** Diese Status darf im Freigabemodus nur die Administration setzen */
	public const APPROVAL_REQUIRED = ['bestaetigt', 'veroeffentlicht'];

	/** Für die Überschneidungsprüfung nicht mehr relevant */
	public const INACTIVE = ['abgesagt', 'archiviert'];

	public const PUBLIC = [
		'frei'        => 'Termin frei',
		'bestaetigt'  => 'Termin bestätigt',
		'abgesagt'    => 'Veranstaltung abgesagt',
		'geschlossen' => 'Geschlossen',
	];

	/**
	 * Öffentlicher Status oder null, wenn der Termin nicht öffentlich
	 * erscheinen darf.
	 */
	public static function publicKey(string $orgStatus, string $category): string|null
	{
		if (
			isset(self::ORG[$orgStatus]) === false ||
			in_array($orgStatus, self::INTERNAL, true) === true
		) {
			return null;
		}

		if ($orgStatus === 'abgesagt') {
			return 'abgesagt';
		}

		// private Veranstaltungen sind für Gäste „geschlossen“
		if (
			$orgStatus === 'geschlossen' ||
			$category === 'geschlossen' ||
			$category === 'privat'
		) {
			return 'geschlossen';
		}

		if ($orgStatus === 'frei') {
			return 'frei';
		}

		return 'bestaetigt';
	}

	public static function publicLabel(string|null $publicKey): string|null
	{
		return $publicKey === null ? null : (self::PUBLIC[$publicKey] ?? null);
	}

	public static function categoryLabel(string $category): string
	{
		return self::CATEGORIES[$category] ?? 'Termin';
	}

	public static function orgLabel(string $orgStatus): string
	{
		return self::ORG[$orgStatus] ?? $orgStatus;
	}

	public static function isActive(string $orgStatus): bool
	{
		return in_array($orgStatus, self::INACTIVE, true) === false;
	}
}
