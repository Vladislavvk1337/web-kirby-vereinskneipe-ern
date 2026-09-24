<?php
/**
 * Kontaktformular-Endpunkt für eine statische Astro-Seite.
 *
 * Erreichbar unter:  POST https://<domain>/api/contact.php
 * Felder:            name, email, message, website (Honeypot, muss leer bleiben)
 * Akzeptiert:        application/json oder application/x-www-form-urlencoded
 *
 * Versand per SMTP mit PHPMailer (composer require phpmailer/phpmailer).
 * Zugangsdaten stehen in ../config.php (außerhalb des Web-Roots).
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Nur POST ---------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'Methode nicht erlaubt.']);
}

// --- Herkunft prüfen (schützt vor Missbrauch durch fremde Seiten) -----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!in_array($origin, $config['allowed_origins'], true)) {
    respond(403, ['ok' => false, 'error' => 'Ungültige Herkunft.']);
}

// --- Eingaben lesen (JSON oder Formular) -------------------------------------
$isJson = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
if ($isJson) {
    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input)) {
        respond(400, ['ok' => false, 'error' => 'Ungültige Anfrage.']);
    }
} else {
    $input = $_POST;
}

$field = static fn (string $key): string => trim((string) ($input[$key] ?? ''));

$name     = $field('name');
$email    = $field('email');
$message  = $field('message');
$honeypot = $field('website');

// Bots füllen das versteckte Feld aus – so tun, als hätte es geklappt
if ($honeypot !== '') {
    respond(200, ['ok' => true]);
}

// --- Validierung ---------------------------------------------------------------
$errors = [];
if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\r\n]/', $name)) {
    $errors['name'] = 'Bitte einen gültigen Namen angeben.';
}
if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors['email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
}
if (mb_strlen($message) < 10 || mb_strlen($message) > 5000) {
    $errors['message'] = 'Die Nachricht muss zwischen 10 und 5000 Zeichen lang sein.';
}
if ($errors) {
    respond(422, ['ok' => false, 'errors' => $errors]);
}

// --- Versand -----------------------------------------------------------------
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $config['smtp']['host'];
    $mail->Port       = (int) $config['smtp']['port'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp']['username'];
    $mail->Password   = $config['smtp']['password'];
    $mail->SMTPSecure = $config['smtp']['encryption'] === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout    = 15;
    $mail->CharSet    = PHPMailer::CHARSET_UTF8;

    // Absender ist immer die eigene Adresse (SPF/DKIM), Antworten gehen an den Besucher
    $mail->setFrom($config['mail']['from'], $config['mail']['from_name']);
    $mail->addAddress($config['mail']['to']);
    $mail->addReplyTo($email, $name);

    $mail->isHTML(false);
    $mail->Subject = sprintf('%s Nachricht von %s', $config['mail']['subject_prefix'], $name);
    $mail->Body    = sprintf(
        "Name:    %s\nE-Mail:  %s\nIP:      %s\nZeit:    %s\n\n%s\n",
        $name,
        $email,
        $_SERVER['REMOTE_ADDR'] ?? '-',
        date('d.m.Y H:i:s'),
        $message
    );

    $mail->send();
} catch (MailException $e) {
    error_log('contact.php: Versand fehlgeschlagen: ' . $mail->ErrorInfo);
    respond(500, ['ok' => false, 'error' => 'Die Nachricht konnte nicht gesendet werden.']);
}

// Klassisches HTML-Formular ohne JavaScript -> Danke-Seite
if (!$isJson && !empty($config['redirect_success'])) {
    header('Location: ' . $config['redirect_success'], true, 303);
    exit;
}

respond(200, ['ok' => true]);
