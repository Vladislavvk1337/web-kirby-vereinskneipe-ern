<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Kneipe\Health;
use Grav\Plugin\Kneipe\Service;

/**
 * Wartung per CLI (z. B. Kubernetes-CronJob):
 *
 *   bin/plugin kneipe maintenance
 *
 * Löscht Anfragen nach Ablauf der Löschfrist und alte Rate-Limit-Zähler.
 */
class MaintenanceCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this->setName('maintenance')
            ->setDescription('Löschfrist für Terminanfragen anwenden und Rate-Limit-Zähler aufräumen');
    }

    protected function serve(): int
    {
        $this->initializePages();

        $grav = Grav::instance();
        require_once __DIR__ . '/../kneipe.php';
        (new \Grav\Plugin\KneipePlugin('kneipe', $grav))->autoload();

        $result = Health::maintenance(Service::instance($grav));

        if ($result === null) {
            $this->output->writeln('<error>Wartung fehlgeschlagen (siehe Log).</error>');

            return 1;
        }

        $this->output->writeln(sprintf(
            'Wartung abgeschlossen: %d Anfrage(n) gelöscht, %d Zähler entfernt.',
            $result['requests'],
            $result['ratelimit']
        ));

        return 0;
    }
}
