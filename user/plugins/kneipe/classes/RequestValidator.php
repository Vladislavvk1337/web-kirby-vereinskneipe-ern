<?php

namespace Grav\Plugin\Kneipe;

use DateTimeImmutable;

/**
 * Serverseitige Prüfung und Normalisierung des Formulars
 * „Freien Termin anfragen“. Ohne Grav-Abhängigkeit testbar.
 */
final class RequestValidator
{
    public const GROUP_TYPES = [
        'verein'      => 'Verein',
        'unternehmen' => 'Unternehmen',
        'initiative'  => 'Initiative',
        'privat'      => 'Private Gruppe',
    ];

    /** Wert für „anderes Datum vorschlagen“ im Feld slot */
    public const OTHER = 'anderer';

    public const FIELDS = [
        'slot'        => 40,
        'wishdate'    => 10,
        'altdate'     => 10,
        'groupname'   => 120,
        'grouptype'   => 20,
        'contactname' => 100,
        'email'       => 254,
        'phone'       => 40,
        'intro'       => 1500,
        'message'     => 3000,
        'privacy'     => 5,
    ];

    /**
     * @param array  $input   Rohdaten (z. B. $_POST)
     * @param array  $slots   erlaubte freie Termine: id => Beschriftung
     * @param string $today   heutiges Datum (Y-m-d)
     * @return array ['values' => array, 'errors' => array<string, string>]
     */
    public static function validate(array $input, array $slots, string $today): array
    {
        $values = [];

        foreach (self::FIELDS as $field => $max) {
            $multiline      = in_array($field, ['intro', 'message'], true);
            $values[$field] = self::normalize($input[$field] ?? '', $multiline, $max);
        }

        $values['email']    = mb_strtolower($values['email']);
        $values['wishdate'] = self::normalizeDate($values['wishdate']);
        $values['altdate']  = self::normalizeDate($values['altdate']);
        $errors             = [];

        // Wunschtermin: freier Termin aus der Liste oder eigenes Datum
        $slot = $values['slot'];

        if ($slot !== '' && $slot !== self::OTHER) {
            if (isset($slots[$slot]) === false) {
                $errors['slot'] = 'Dieser Termin ist leider nicht mehr frei. Bitte wählt einen anderen.';
            }

            // eigenes Datum ist nur bei „anderes Datum“ relevant
            $values['wishdate'] = '';
        } elseif ($values['wishdate'] === '') {
            $errors['slot'] = 'Bitte wählt einen freien Termin aus oder schlagt ein eigenes Datum vor.';

            if ($slot === self::OTHER) {
                $errors['wishdate'] = 'Bitte gebt ein Wunschdatum an.';
            }
        } elseif ($error = self::dateError($values['wishdate'], $today)) {
            $errors['wishdate'] = $error;
        } else {
            $values['slot'] = self::OTHER;
        }

        if ($values['altdate'] !== '' && ($error = self::dateError($values['altdate'], $today))) {
            $errors['altdate'] = $error;
        }

        if (mb_strlen($values['groupname']) < 2) {
            $errors['groupname'] = 'Bitte gebt den Namen eurer Gruppe an.';
        }

        if (isset(self::GROUP_TYPES[$values['grouptype']]) === false) {
            $errors['grouptype'] = 'Bitte wählt aus, was für eine Gruppe ihr seid.';
        }

        if (mb_strlen($values['contactname']) < 2) {
            $errors['contactname'] = 'Bitte gebt an, wer eure Ansprechperson ist.';
        }

        if ($values['email'] === '') {
            $errors['email'] = 'Bitte gebt eine E-Mail-Adresse an, damit wir antworten können.';
        } elseif (
            filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $values['email']) !== 1
        ) {
            $errors['email'] = 'Diese E-Mail-Adresse sieht nicht vollständig aus. Beispiel: name@example.org';
        }

        if ($values['phone'] !== '' && preg_match('/^\+?[0-9 ()\/.\-]{6,30}$/', $values['phone']) !== 1) {
            $errors['phone'] = 'Bitte nur Ziffern, Leerzeichen und + ( ) / - verwenden – oder das Feld leer lassen.';
        }

        if (mb_strlen($values['intro']) < 10) {
            $errors['intro'] = 'Erzählt uns bitte in ein, zwei Sätzen, wer ihr seid.';
        }

        if ($values['privacy'] !== 'ja') {
            $errors['privacy'] = 'Bitte bestätigt, dass ihr die Datenschutzerklärung gelesen habt.';
        }

        foreach (self::FIELDS as $field => $max) {
            if (is_string($input[$field] ?? null) && mb_strlen($input[$field]) > $max * 2) {
                $errors[$field] = 'Dieser Text ist zu lang.';
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Entfernt Steuerzeichen, vereinheitlicht Zeilenumbrüche und Leerraum,
     * kürzt auf die Höchstlänge.
     */
    public static function normalize(mixed $value, bool $multiline, int $max): string
    {
        if (is_string($value) === false) {
            return '';
        }

        if (mb_check_encoding($value, 'UTF-8') === false) {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? '';

        if ($multiline === true) {
            $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';
            $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? '';
        } else {
            $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        }

        return mb_substr(trim($value), 0, $max);
    }

    /** Akzeptiert auch TT.MM.JJJJ (Browser ohne Datumsauswahl) */
    public static function normalizeDate(string $value): string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return $value;
    }

    public static function dateError(string $value, string $today): string|null
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return 'Bitte ein Datum im Format TT.MM.JJJJ angeben.';
        }

        $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today);

        if ($date <= $todayDate) {
            return 'Bitte ein Datum in der Zukunft wählen.';
        }

        if ($date > $todayDate->modify('+2 years')) {
            return 'Anfragen sind höchstens zwei Jahre im Voraus möglich.';
        }

        return null;
    }
}
