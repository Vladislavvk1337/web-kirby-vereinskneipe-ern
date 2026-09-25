<?php

namespace Grav\Plugin\Kneipe;

/**
 * iCalendar-Ausgabe nach RFC 5545.
 *
 * Zeiten werden in UTC ausgegeben (…Z) – dann braucht es keinen
 * VTIMEZONE-Block, und jede Kalender-App rechnet korrekt in die
 * Ortszeit um. Ganztägige Termine verwenden VALUE=DATE.
 *
 * Ein Termin ist ein Array:
 *   uid, start, end (Zeitstempel), allday (bool), summary,
 *   description, location, url, status (CONFIRMED|CANCELLED|TENTATIVE),
 *   modified (Zeitstempel), sequence (int), categories (string)
 */
final class Ics
{
    public const CRLF = "\r\n";

    public static function calendar(array $events, string $name, string $prodId, int|null $now = null): string
    {
        $now   = $now ?? time();
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::text($prodId),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text($name),
            'X-PUBLISHED-TTL:PT6H',
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
        ];

        foreach ($events as $event) {
            array_push($lines, ...self::event($event, $now));
        }

        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map(self::fold(...), $lines)) . self::CRLF;
    }

    public static function event(array $event, int $now): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . self::text($event['uid']),
            'DTSTAMP:' . self::utc($now),
        ];

        if (($event['allday'] ?? false) === true) {
            $lines[] = 'DTSTART;VALUE=DATE:' . date('Ymd', $event['start']);
            $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', $event['end']);
        } else {
            $lines[] = 'DTSTART:' . self::utc($event['start']);
            $lines[] = 'DTEND:' . self::utc($event['end']);
        }

        $lines[] = 'SUMMARY:' . self::text($event['summary'] ?? '');

        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION', 'categories' => 'CATEGORIES'] as $key => $property) {
            if (($event[$key] ?? '') !== '') {
                $lines[] = $property . ':' . self::text($event[$key]);
            }
        }

        if (($event['url'] ?? '') !== '') {
            // URI-Werte werden nicht wie Text maskiert
            $lines[] = 'URL:' . preg_replace('/[\r\n]/', '', $event['url']);
        }

        $lines[] = 'STATUS:' . ($event['status'] ?? 'CONFIRMED');

        if (isset($event['modified']) === true) {
            $lines[] = 'LAST-MODIFIED:' . self::utc($event['modified']);
        }

        $lines[] = 'SEQUENCE:' . (int)($event['sequence'] ?? 0);
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    public static function utc(int $timestamp): string
    {
        return gmdate('Ymd\THis\Z', $timestamp);
    }

    /** TEXT-Werte maskieren (RFC 5545, 3.3.11) */
    public static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value) ?? '';

        return strtr($value, [
            '\\' => '\\\\',
            ';'  => '\;',
            ','  => '\,',
            "\n" => '\n',
        ]);
    }

    /**
     * Zeilen nach 75 Oktetten falten, ohne UTF-8-Zeichen zu zerteilen.
     * Folgezeilen beginnen mit einem Leerzeichen.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts   = [];
        $current = '';
        $limit   = 75;

        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $parts[] = $current;
                $current = '';
                // Folgezeilen: 1 Oktett geht für das Leerzeichen ab
                $limit   = 74;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return implode(self::CRLF . ' ', $parts);
    }
}
