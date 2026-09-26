<?php

namespace Grav\Plugin\Kneipe;

/**
 * Health-Checks für Kubernetes und Docker.
 *
 *   /healthz – Liveness: PHP und Grav antworten (ohne Seiten zu laden)
 *   /readyz  – Readiness: Inhalte vorhanden, mindestens ein Konto,
 *              Laufzeitdaten beschreibbar. Nebenbei höchstens einmal
 *              täglich die Löschfrist für Anfragen anwenden und alte
 *              Rate-Limit-Zähler entfernen.
 *
 * Beide laufen vor dem Laden der Seiten (onPluginsInitialized).
 *
 * Antworten enthalten keine Details (keine Pfade, keine Versionen).
 */
final class Health
{
    public const HEADERS = ['Cache-Control' => 'no-store'];

    public static function live(): string
    {
        return "ok\n";
    }

    /** @return array{0: bool, 1: string} */
    public static function ready(Service $service): array
    {
        $locator  = $service->grav()['locator'];
        $writable = fn (?string $dir): bool => $dir !== null && $dir !== '' && (is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir)));

        $pages    = (string)$locator->findResource('page://', true);
        $accounts = (string)$locator->findResource('account://', true);
        $ready    = is_dir($pages)
            && glob($pages . '/einstellungen/*.md') !== []
            && glob($accounts . '/*.yaml') !== []
            && $writable($pages)
            && $writable($accounts)
            && $writable((string)$locator->findResource('user-data://', true))
            && $writable((string)$locator->findResource('cache://', true))
            && $writable((string)$locator->findResource('tmp://', true, true));

        if ($ready && self::maintenanceDue($service)) {
            try {
                $service->pages()->init();
                self::maintenance($service);
            } catch (\Throwable $e) {
                $service->grav()['log']->warning('[kneipe] Wartung über /readyz nicht möglich: ' . $e::class);
            }
        }

        return [$ready, $ready ? "ok\n" : "nicht bereit\n"];
    }

    public static function maintenanceDue(Service $service): bool
    {
        $marker = $service->dataDir() . '/maintenance';

        return !is_file($marker) || filemtime($marker) <= time() - 86400;
    }

    /** Wartung (Löschfrist, Zähler); die Markierungsdatei begrenzt auf einmal täglich */
    public static function maintenance(Service $service): ?array
    {
        $dir    = $service->dataDir();
        $marker = $dir . '/maintenance';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @touch($marker);

        try {
            $deleted = Retention::cleanup($service, $service->retentionDays());
            $pruned  = (new RequestForm($service->grav(), $service))->limiter()->prune();
        } catch (\Throwable $e) {
            $service->grav()['log']->warning('[kneipe] Tägliche Wartung fehlgeschlagen: ' . $e::class);

            return null;
        }

        return ['requests' => $deleted, 'ratelimit' => $pruned];
    }
}
