<?php

namespace Grav\Plugin\Kneipe;

use Grav\Common\Filesystem\Folder;

/**
 * Löschfrist für Terminanfragen: Anfragen, die älter als die in den
 * Einstellungen gepflegte Frist sind, werden endgültig gelöscht.
 *
 * Läuft bei jeder neuen Anfrage, höchstens täglich über /readyz und
 * (optional) über den Kubernetes-CronJob bzw. `bin/plugin kneipe maintenance`.
 */
final class Retention
{
    /** @return int Anzahl gelöschter Anfragen */
    public static function cleanup(Service $service, int $days, ?int $now = null): int
    {
        $now     = $now ?? time();
        $limit   = $now - $days * 86400;
        $deleted = 0;

        foreach ($service->requests() as $request) {
            $submitted = strtotime((string)($request->header()->submittedat ?? '')) ?: (int)$request->modified();

            if ($submitted < $limit && is_dir((string)$request->path())) {
                Folder::delete((string)$request->path());
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $pages = $service->grav()['pages'];

            if (method_exists($pages, 'markChanged')) {
                $pages->markChanged();
            }

            $service->flush();
        }

        return $deleted;
    }
}
