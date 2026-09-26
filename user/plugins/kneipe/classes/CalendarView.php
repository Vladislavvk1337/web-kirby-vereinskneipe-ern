<?php

namespace Grav\Plugin\Kneipe;

/**
 * Daten für die Kalenderseite (/termine): Filter, Monatsraster, Liste,
 * Rückblick. Ungültige Parameter fallen auf Standardwerte zurück.
 */
final class CalendarView
{
    public const AVAILABILITY = [
        ''            => 'Alle Termine',
        'frei'        => 'Nur freie Termine',
        'bestaetigt'  => 'Nur bestätigte Termine',
        'abgesagt'    => 'Abgesagte Veranstaltungen',
        'geschlossen' => 'Geschlossene Tage',
    ];

    public static function build(Service $service, string $baseUrl, array $query, ?int $now = null): array
    {
        $now   = $now ?? time();
        // Nur einfache Zeichenketten akzeptieren (keine Arrays aus ?x[]=…)
        $param = fn (string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';

        $category     = $param('art');
        $availability = $param('verfuegbarkeit');
        $period       = $param('zeitraum') === 'vergangen' ? 'vergangen' : '';
        $hasMonth     = $param('monat') !== '' || $param('jahr') !== '';

        if (isset(EventStatus::CATEGORIES[$category]) === false || $category === 'privat') {
            $category = '';
        }

        if (isset(EventStatus::PUBLIC[$availability]) === false) {
            $availability = '';
        }

        [$year, $month] = Calendar::normalizeMonth($param('jahr'), $param('monat'), $now);
        [$from, $to]    = Calendar::monthRange($year, $month);

        $filters = [
            'art'            => $category,
            'verfuegbarkeit' => $availability,
            'jahr'           => $hasMonth ? (string)$year : '',
            'monat'          => $hasMonth ? (string)$month : '',
            'zeitraum'       => $period,
        ];

        $urlFor = fn (int $y, int $m): string => $baseUrl . '?' . http_build_query(array_filter([
            'jahr'           => $y,
            'monat'          => $m,
            'art'            => $category,
            'verfuegbarkeit' => $availability,
        ])) . '#kalender';

        $monthEvents = $service->calendarEvents($from, $to, $category, $availability, false, $now);

        if ($period === 'vergangen') {
            $listEvents = $service->calendarEvents(null, null, $category, $availability, true, $now);
            $listTitle  = 'Rückblick: vergangene Termine';
        } elseif ($hasMonth) {
            $listEvents = $monthEvents;
            $listTitle  = 'Termine im ' . Calendar::monthLabel($year, $month);
        } else {
            $listEvents = $service->calendarEvents(null, null, $category, $availability, false, $now);
            $listTitle  = 'Kommende Termine';
        }

        [$py, $pm] = Calendar::shiftMonth($year, $month, -1);
        [$ny, $nm] = Calendar::shiftMonth($year, $month, 1);

        $currentYear = (int)date('Y', $now);

        return [
            'year'         => $year,
            'month'        => $month,
            'monthLabel'   => Calendar::monthLabel($year, $month),
            'filters'      => $filters,
            'active'       => $category !== '' || $availability !== '',
            'categories'   => array_diff_key(EventStatus::CATEGORIES, ['privat' => true]),
            'availability' => self::AVAILABILITY,
            'months'       => Calendar::MONTHS,
            'years'        => range($currentYear - 2, $currentYear + 2),
            'nav'          => [
                ['url' => $urlFor($year - 1, $month), 'label' => Calendar::monthLabel($year - 1, $month) . ' (ein Jahr zurück)', 'icon' => 'double-left', 'before' => true],
                ['url' => $urlFor($py, $pm), 'label' => Calendar::monthLabel($py, $pm) . ' (vorheriger Monat)', 'icon' => 'chevron-left', 'before' => true],
                ['url' => $urlFor($ny, $nm), 'label' => Calendar::monthLabel($ny, $nm) . ' (nächster Monat)', 'icon' => 'chevron-right', 'before' => false],
                ['url' => $urlFor($year + 1, $month), 'label' => Calendar::monthLabel($year + 1, $month) . ' (ein Jahr weiter)', 'icon' => 'double-right', 'before' => false],
            ],
            'weeks'        => self::weeks($year, $month, $monthEvents, $now),
            'weekdays'     => array_map(null, Calendar::WEEKDAYS, Calendar::WEEKDAYS_LONG),
            'listTitle'    => $listTitle,
            'listCount'    => count($listEvents),
            'groups'       => $service->groupByMonth($listEvents),
            'past'         => $period === '' ? $service->pastEvents(4, $now) : [],
            // gefilterte Ansichten nicht indexieren, Canonical auf die Grundseite
            'noindex'      => array_filter($filters) !== [],
        ];
    }

    /** Monatsraster mit den Terminen je Tag */
    private static function weeks(int $year, int $month, array $events, int $now): array
    {
        $today = date('Y-m-d', $now);
        $short = ['frei' => 'frei', 'bestaetigt' => '', 'abgesagt' => 'abgesagt', 'geschlossen' => 'geschlossen'];
        $weeks = [];

        foreach (Calendar::monthGrid($year, $month) as $week) {
            $row = [];

            foreach ($week as $day) {
                $dayEvents = array_values(array_filter(
                    $events,
                    fn (EventEntry $e) => $e->startTimestamp() < $day['end'] && $e->endTimestamp() > $day['start']
                ));

                $classes = ['month-grid__day'];

                if ($day['inMonth'] === false) {
                    $classes[] = 'is-outside';
                }

                if ($day['date'] === $today) {
                    $classes[] = 'is-today';
                }

                if ($day['date'] < $today) {
                    $classes[] = 'is-past';
                }

                $row[] = [
                    'day'     => $day['day'],
                    'today'   => $day['date'] === $today,
                    'class'   => implode(' ', $classes),
                    'events'  => array_map(fn (EventEntry $e) => [
                        'entry' => $e,
                        'flag'  => $short[$e->publicStatus()] ?? '',
                    ], $dayEvents),
                ];
            }

            $weeks[] = $row;
        }

        return $weeks;
    }
}
