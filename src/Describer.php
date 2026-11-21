<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit;

/**
 * Builds a human-readable English description of a {@see CronExpression}.
 *
 * Examples:
 *  - `* * * * *`             => "Every minute"
 *  - `30 9 * * MON-FRI`      => "At 09:30, Monday through Friday"
 *  - `*\/15 9-17 * * *`      => "Every 15 minutes, between 09:00 and 17:59"
 *  - `0 12 * * 5L`           => "At 12:00, on the last Friday of the month"
 */
final class Describer
{
    private const array MONTHS = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    private const array WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const array ORDINALS = [1 => 'first', 'second', 'third', 'fourth', 'fifth'];

    public function describe(CronExpression $cron): string
    {
        $segments = [
            ...$this->timeSegments($cron),
            $this->daySegment($cron),
            $this->monthSegment($cron->month),
        ];

        return ucfirst(implode(', ', array_filter($segments, static fn(string $s): bool => $s !== '')));
    }

    /**
     * @return list<string>
     */
    private function timeSegments(CronExpression $cron): array
    {
        $second = $cron->second;
        $minute = $cron->minute;
        $hour = $cron->hour;
        $withSeconds = $cron->withSeconds && !($this->onlyValues($second) && $second->values === [0]);

        // Fixed times: "At 09:30", "At 09:00 and 17:00".
        if ($this->onlyValues($minute) && $this->onlyValues($hour) && $this->onlyValues($second)
            && count($minute->values) * count($hour->values) * count($second->values) <= 6) {
            $times = [];
            foreach ($hour->values as $h) {
                foreach ($minute->values as $m) {
                    foreach ($second->values as $s) {
                        $times[] = sprintf('%02d:%02d', $h, $m) . ($withSeconds ? sprintf(':%02d', $s) : '');
                    }
                }
            }

            return ['at ' . self::join($times)];
        }

        $segments = [];
        if ($withSeconds) {
            $segments[] = $this->unitPhrase($second, 'second');
        }

        $minuteIsZero = $this->onlyValues($minute) && $minute->values === [0];
        if (!$withSeconds && $minuteIsZero && $hour->isAll()) {
            return ['every hour'];
        }
        if (!$withSeconds && $minuteIsZero && count($hour->parts) === 1
            && $hour->parts[0]->kind === PartKind::All && $hour->parts[0]->hasStep()) {
            return [sprintf('every %d hours', $hour->parts[0]->step)];
        }

        if (!($withSeconds && $minute->isAll())) {
            $phrase = $this->unitPhrase($minute, 'minute');
            if ($hour->isAll() && $this->onlyValues($minute)) {
                $phrase .= ' of every hour';
            }
            $segments[] = $phrase;
        }

        if (!$hour->isAll()) {
            $segments[] = $this->hourPhrase($hour);
        }

        return $segments;
    }

    private function unitPhrase(Field $field, string $unit): string
    {
        $items = [];
        $values = [];
        foreach ($field->parts as $part) {
            switch ($part->kind) {
                case PartKind::All:
                    $items[] = $part->hasStep() ? sprintf('every %d %ss', $part->step, $unit) : sprintf('every %s', $unit);
                    break;
                case PartKind::Value:
                    if ($part->hasStep()) {
                        $items[] = sprintf('every %d %ss starting at %s %d', $part->step, $unit, $unit, $part->start);
                    } else {
                        $values[] = (string) $part->start;
                    }
                    break;
                default:
                    $items[] = $part->hasStep()
                        ? sprintf('every %d %ss from %d through %d', $part->step, $unit, $part->start, $part->end)
                        : sprintf('every %s from %d through %d', $unit, $part->start, $part->end);
            }
        }
        if ($values !== []) {
            array_unshift($items, sprintf('at %s%s %s', $unit, count($values) > 1 ? 's' : '', self::join($values)));
        }

        return implode(' and ', $items);
    }

    private function hourPhrase(Field $hour): string
    {
        $items = [];
        $values = [];
        foreach ($hour->parts as $part) {
            switch ($part->kind) {
                case PartKind::All:
                    $items[] = sprintf('every %d hours', $part->step);
                    break;
                case PartKind::Value:
                    if ($part->hasStep()) {
                        $items[] = sprintf('every %d hours starting at %02d:00', $part->step, $part->start);
                    } else {
                        $values[] = $part->start;
                    }
                    break;
                default:
                    $between = sprintf('between %02d:00 and %02d:59', $part->start, $part->end);
                    $items[] = $part->hasStep() ? sprintf('every %d hours %s', $part->step, $between) : $between;
            }
        }
        if (count($values) === 1) {
            array_unshift($items, sprintf('between %02d:00 and %02d:59', $values[0], $values[0]));
        } elseif ($values !== []) {
            array_unshift($items, 'during hours ' . self::join(array_map(static fn(int $h): string => sprintf('%02d', $h), $values)));
        }

        return implode(' and ', $items);
    }

    private function daySegment(CronExpression $cron): string
    {
        $dom = $cron->dayOfMonth->isAll() ? '' : $this->dayOfMonthPhrase($cron->dayOfMonth);
        $dow = $cron->dayOfWeek->isAll() ? '' : $this->dayOfWeekPhrase($cron->dayOfWeek);
        if ($dom !== '' && $dow !== '') {
            $or = !$cron->dayOfMonth->isWildcard() && !$cron->dayOfWeek->isWildcard();

            return $dom . ($or ? ', or ' : ', ') . $dow;
        }

        return $dom . $dow;
    }

    private function dayOfMonthPhrase(Field $field): string
    {
        $items = [];
        $values = [];
        foreach ($field->parts as $part) {
            $items[] = match ($part->kind) {
                PartKind::All => $part->hasStep() ? sprintf('every %d days', $part->step) : 'every day',
                PartKind::Value => $part->hasStep()
                    ? sprintf('every %d days starting on day %d of the month', $part->step, $part->start)
                    : null,
                PartKind::Range => $part->hasStep()
                    ? sprintf('every %d days from day %d through %d of the month', $part->step, $part->start, $part->end)
                    : sprintf('on days %d through %d of the month', $part->start, $part->end),
                PartKind::LastDayOfMonth => $part->start === 0
                    ? 'on the last day of the month'
                    : sprintf('%d day%s before the last day of the month', $part->start, $part->start > 1 ? 's' : ''),
                PartKind::LastWeekdayOfMonth => 'on the last weekday of the month',
                PartKind::NearestWeekday => sprintf('on the weekday nearest day %d of the month', $part->start),
                default => null,
            };
            if ($part->kind === PartKind::Value && !$part->hasStep()) {
                $values[] = (string) $part->start;
            }
        }
        $items = array_values(array_filter($items, static fn(?string $i): bool => $i !== null));
        if ($values !== []) {
            array_unshift($items, sprintf('on day%s %s of the month', count($values) > 1 ? 's' : '', self::join($values)));
        }

        return implode(' and ', $items);
    }

    private function dayOfWeekPhrase(Field $field): string
    {
        $items = [];
        $values = [];
        foreach ($field->parts as $part) {
            $day = self::WEEKDAYS[$part->start];
            switch ($part->kind) {
                case PartKind::All:
                    $items[] = $part->hasStep() ? sprintf('every %d days of the week', $part->step) : 'every day';
                    break;
                case PartKind::Value:
                    if ($part->hasStep()) {
                        $items[] = sprintf('every %d days of the week starting on %s', $part->step, $day);
                    } else {
                        $values[] = $day;
                    }
                    break;
                case PartKind::Range:
                    $range = sprintf('%s through %s', $day, self::WEEKDAYS[$part->end]);
                    $items[] = $part->hasStep() ? sprintf('every %d days of the week from %s', $part->step, $range) : $range;
                    break;
                case PartKind::LastWeekdayOccurrence:
                    $items[] = sprintf('on the last %s of the month', $day);
                    break;
                case PartKind::NthWeekday:
                    $items[] = sprintf('on the %s %s of the month', self::ORDINALS[$part->end], $day);
                    break;
                default:
                    break;
            }
        }
        if ($values !== []) {
            array_unshift($items, 'on ' . self::join(array_values(array_unique($values))));
        }

        return implode(' and ', $items);
    }

    private function monthSegment(Field $field): string
    {
        if ($field->isAll()) {
            return '';
        }

        $items = [];
        $values = [];
        foreach ($field->parts as $part) {
            $name = self::MONTHS[$part->start] ?? '';
            switch ($part->kind) {
                case PartKind::All:
                    $items[] = sprintf('every %d months', $part->step);
                    break;
                case PartKind::Value:
                    if ($part->hasStep()) {
                        $items[] = sprintf('every %d months starting in %s', $part->step, $name);
                    } else {
                        $values[] = $name;
                    }
                    break;
                default:
                    $range = sprintf('from %s through %s', $name, self::MONTHS[$part->end] ?? '');
                    $items[] = $part->hasStep() ? sprintf('every %d months %s', $part->step, $range) : $range;
            }
        }
        if ($values !== []) {
            array_unshift($items, 'in ' . self::join($values));
        }

        return implode(' and ', $items);
    }

    private function onlyValues(Field $field): bool
    {
        foreach ($field->parts as $part) {
            if ($part->kind !== PartKind::Value || $part->hasStep()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $items
     */
    private static function join(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
