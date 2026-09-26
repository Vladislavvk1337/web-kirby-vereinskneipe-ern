<?php

namespace Grav\Plugin\Kneipe;

/**
 * Regeln des Freigabeworkflows – ohne Grav-Abhängigkeit, damit sie
 * direkt getestet werden können. Guard.php setzt sie im Admin und in der API durch.
 *
 * Rollen: „admin“ darf alles. „moderator“ darf im Freigabemodus weder
 * veröffentlichen noch die Status „bestätigt“/„veröffentlicht“ setzen;
 * er bereitet Termine vor und setzt sie auf „zur Freigabe“.
 */
final class Workflow
{
    public static function isPrivileged(string|null $role): bool
    {
        return $role === 'admin';
    }

    /**
     * Darf die Rolle den Veröffentlichungsstatus (published) eines Termins ändern?
     */
    public static function canChangePublicationStatus(string|null $role, bool $approvalMode): bool
    {
        if (self::isPrivileged($role) === true) {
            return true;
        }

        return $role === 'moderator' && $approvalMode === false;
    }

    /**
     * Darf die Rolle den organisatorischen Status von $from auf $to setzen?
     * Unveränderte Werte sind immer erlaubt.
     */
    public static function canSetOrgStatus(string|null $role, bool $approvalMode, string $from, string $to): bool
    {
        if ($from === $to || self::isPrivileged($role) === true) {
            return true;
        }

        if ($role !== 'moderator') {
            return false;
        }

        if ($approvalMode === true && in_array($to, EventStatus::APPROVAL_REQUIRED, true) === true) {
            return false;
        }

        return true;
    }

    /**
     * Darf die Rolle einen Termin löschen? Moderatoren nur Entwürfe.
     */
    public static function canDeleteEvent(string|null $role, bool $isDraft): bool
    {
        return self::isPrivileged($role) === true || ($role === 'moderator' && $isDraft === true);
    }

    /**
     * Darf ein Termin mit Überschneidungen veröffentlicht bzw. bestätigt
     * werden? Nur wenn die Überschneidung ausdrücklich zugelassen wurde.
     */
    public static function canPublishWithConflicts(int $conflicts, bool $accepted): bool
    {
        return $conflicts === 0 || $accepted === true;
    }
}
