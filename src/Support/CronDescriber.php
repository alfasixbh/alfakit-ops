<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitOps\Support;

use Carbon\CarbonImmutable;

/**
 * A cron expression in words, in the application's locale: "Every 5 minutes", "Every day
 * at 02:00", "Weekdays at 08:30", "Monthly on day 1 at 00:00". Expressions it can't put
 * into words return null (show the expression itself).
 */
final class CronDescriber
{
    private const MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    private const DAY_NAMES = ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6];

    public static function describe(string $expression, ?int $repeatSeconds = null): ?string
    {
        $base = self::describeExpression($expression);

        if ($repeatSeconds !== null && $repeatSeconds > 0 && $repeatSeconds < 60) {
            $seconds = self::trans('every_n_seconds', ['count' => $repeatSeconds]);

            return $expression === '* * * * *' || $base === null ? $seconds : $seconds.' · '.$base;
        }

        return $base;
    }

    private static function describeExpression(string $expression): ?string
    {
        $expression = trim($expression);
        $expression = self::MACROS[strtolower($expression)] ?? $expression;
        $fields = preg_split('/\s+/', $expression) ?: [];

        if (count($fields) !== 5) {
            return null;
        }

        [$minute, $hour, $day, $month, $weekday] = $fields;
        $everyDay = $day === '*' && $month === '*' && $weekday === '*';

        if ($minute === '*' && $hour === '*' && $everyDay) {
            return self::trans('every_minute');
        }

        if (preg_match('/^\*\/(\d+)$/', $minute, $step) === 1 && $hour === '*' && $everyDay) {
            return (int) $step[1] === 1 ? self::trans('every_minute') : self::trans('every_n_minutes', ['count' => (int) $step[1]]);
        }

        $minutes = self::numbers($minute, 0, 59);

        if ($minutes === null) {
            return null;
        }

        if ($hour === '*' && $everyDay) {
            return self::trans('hourly', ['minutes' => self::list(array_map(static fn (int $m): string => sprintf(':%02d', $m), $minutes))]);
        }

        if (preg_match('/^\*\/(\d+)$/', $hour, $step) === 1 && $everyDay && count($minutes) === 1) {
            return self::trans('every_n_hours', ['count' => (int) $step[1], 'minutes' => sprintf(':%02d', $minutes[0])]);
        }

        $hours = self::numbers($hour, 0, 23);

        if ($hours === null) {
            return null;
        }

        $times = [];

        foreach ($hours as $h) {
            foreach ($minutes as $m) {
                $times[] = sprintf('%02d:%02d', $h, $m);
            }
        }

        $time = self::list($times);

        if ($everyDay) {
            return self::trans('daily', ['time' => $time]);
        }

        if ($day === '*' && $month === '*') {
            $weekdays = self::numbers(strtr(strtoupper($weekday), array_map(strval(...), self::DAY_NAMES)), 0, 7);

            if ($weekdays === null) {
                return null;
            }

            $weekdays = array_values(array_unique(array_map(static fn (int $d): int => $d % 7, $weekdays)));
            sort($weekdays);

            return match ($weekdays) {
                [1, 2, 3, 4, 5] => self::trans('weekdays', ['time' => $time]),
                [0, 6] => self::trans('weekends', ['time' => $time]),
                default => self::trans('weekly', ['days' => self::list(array_map(self::dayName(...), $weekdays)), 'time' => $time]),
            };
        }

        $days = self::numbers($day, 1, 31);

        if ($days === null || $weekday !== '*') {
            return null;
        }

        if ($month === '*') {
            return self::trans('monthly', ['days' => self::list(array_map(strval(...), $days)), 'time' => $time]);
        }

        $months = self::numbers($month, 1, 12);

        if ($months === null || count($days) !== 1) {
            return null;
        }

        if (count($months) === 4 && $months[1] - $months[0] === 3 && $months[2] - $months[1] === 3 && $months[3] - $months[2] === 3) {
            return self::trans('quarterly', ['days' => (string) $days[0], 'time' => $time]);
        }

        return self::trans('yearly', [
            'date' => self::list(array_map(static fn (int $m): string => self::date($m, $days[0]), $months)),
            'time' => $time,
        ]);
    }

    /**
     * A field of plain numbers, lists and ranges (`5`, `1,15`, `1-5`, `0-30/10`), or null.
     *
     * @return list<int>|null
     */
    private static function numbers(string $field, int $min, int $max): ?array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\d+)(?:-(\d+))?(?:\/(\d+))?$/', $part, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
                return null;
            }

            $from = (int) $matches[1];
            $to = $matches[2] !== null ? (int) $matches[2] : $from;
            $step = $matches[3] !== null ? max(1, (int) $matches[3]) : 1;

            if ($from < $min || $to > $max || $from > $to) {
                return null;
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        // Long lists don't read better than the expression.
        return $values === [] || count($values) > 12 ? null : $values;
    }

    private static function dayName(int $day): string
    {
        return CarbonImmutable::create(2023, 1, 1 + $day)->locale(app()->getLocale())->isoFormat('dddd');
    }

    private static function date(int $month, int $day): string
    {
        return CarbonImmutable::create(2024, $month, 1)->locale(app()->getLocale())->isoFormat('MMMM').' '.$day;
    }

    /**
     * @param  array<string>  $items
     */
    private static function list(array $items): string
    {
        return implode(', ', $items);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function trans(string $key, array $replace = []): string
    {
        return (string) __('alfakit-ops::ops.cron.'.$key, $replace);
    }
}
