<?php
/**
 * SMTP-Zugangsdaten und Einstellungen für den Mailversand.
 *
 * Diese Datei liegt AUSSERHALB des Web-Roots und ist nur für den Admin
 * und PHP (www-data) lesbar (chmod 640). Niemals ins Git-Repository!
 */
return [
    'smtp' => [
        'host'       => 'smtp.example.de',
        'port'       => 587,
        'encryption' => 'tls',   // 'tls' (STARTTLS, Port 587) oder 'ssl' (Port 465)
        'username'   => 'noreply@${DOMAIN}',
        'password'   => 'CHANGE_ME',
    ],
    'mail' => [
        'from'           => 'noreply@${DOMAIN}',
        'from_name'      => '${DOMAIN}',
        'to'             => 'info@${DOMAIN}',
        'subject_prefix' => '[Kontaktformular]',
    ],
    // Nur Anfragen von diesen Origins werden akzeptiert
    'allowed_origins' => [${ALLOWED_ORIGINS}],
    // Optional: Weiterleitung nach erfolgreichem Versand ohne JavaScript
    'redirect_success' => '/danke/',
];
