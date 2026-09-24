<?php

/**
 * Terminanfragen nach Ablauf der Löschfrist (Panel → Einstellungen)
 * endgültig löschen. Läuft täglich per systemd-Timer (kneipe-cleanup.timer).
 *
 *   php bin/cleanup-requests.php
 */

use Kneipe\RateLimiter;
use Kneipe\Retention;

$kirby   = require __DIR__ . '/kirby.php';
$days    = kneipe()->retentionDays();
$deleted = Retention::cleanup($kirby, $days);

// abgelaufene Zähler des Rate-Limits entfernen
(new RateLimiter($kirby->root('cache') . '/kneipe-ratelimit', kneipe()->secret()))->prune();

echo date('Y-m-d H:i:s') . " Löschfrist {$days} Tage – {$deleted} Anfrage(n) gelöscht.\n";
