<?php

/**
 * Grav-Setup für Container und lokale Entwicklung.
 *
 * Grav lädt diese Datei vor der Konfiguration (Grav\Common\Config\Setup).
 * Sie legt eine zusätzliche, beschreibbare Konfigurationsebene unter
 * $GRAV_DATA_DIR/config vor alle anderen. Dort speichert Grav seinen
 * geheimen Schlüssel für CSRF-Nonces (security-private.php); der
 * Entrypoint kann ihn aus einem Secret (GRAV_NONCE_KEY) vorgeben.
 *
 * Reihenfolge (erste gewinnt):
 *   1. $GRAV_DATA_DIR/config        – Laufzeit, persistentes Volume
 *   2. user/env/<GRAV_ENVIRONMENT>  – dev, staging, production (Image)
 *   3. user/config                  – Grundkonfiguration (Image)
 *   4. system/config                – Grav-Standard
 *
 * Seiten, Konten und Formulardaten liegen ebenfalls unter $GRAV_DATA_DIR;
 * das Image verlinkt user/pages, user/accounts und user/data dorthin
 * (Symlinks statt Stream-Umleitung, damit Medien-URLs gültig bleiben).
 *
 * Einzelne Einstellungen lassen sich zusätzlich mit Grav-eigenen
 * Umgebungsvariablen überschreiben: GRAV_CONFIG=true und
 * GRAV_CONFIG__<bereich>__<schlüssel>=<wert> (siehe Docker.md).
 */

$dataDir = rtrim((string)(getenv('GRAV_DATA_DIR') ?: '/data'), '/');

return [
    'streams' => [
        'schemes' => [
            'config' => [
                'type'     => 'ReadOnlyStream',
                'prefixes' => [
                    '' => [
                        $dataDir . '/config',
                        'environment://config',
                        'user://config',
                        'system://config',
                    ],
                ],
            ],
        ],
    ],
];
