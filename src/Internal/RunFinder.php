<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit\Internal;

use DateTimeZone;
use YilmazMurad\Cronkit\Field;
use YilmazMurad\Cronkit\FieldPart;
use YilmazMurad\Cronkit\PartKind;

/**
 * Finds run instants of a cron expression in a given time zone.
 *
 * The search works on "wall-clock" timestamps (local date/time encoded as if it were UTC)
 * inside stretches of time with a constant UTC offset, which makes DST handling explicit:
 *
 *  - Spring forward (gap): local times that do not exist are skipped. If the hour field is
 *    restricted (not every hour) and a matching local time falls into the gap, the job runs
 *    once at the transition instant (e.g. 03:00 instead of 02:30), like Vixie cron.
 *  - Fall back (overlap): repeated local times run once (first occurrence) unless the hour
 *    field matches every hour, in which case both occurrences run (e.g. `*\/15 * * * *`).
 *
 * @internal
 */
final class RunFinder
{
    private const int DAY = 86_400;
    private const int WINDOW = 366 * self::DAY;

    /** @var array<int, true> */
    private array $months;

    /** @var list<FieldPart> */
    private array $domSpecials;

    /** @var list<FieldPart> */
    private array $dowSpecials;

    public function __construct(
        private readonly Field $second,
        private readonly Field $minute,
        private readonly Field $hour,
        private readonly Field $dayOfMonth,
        private readonly Field $month,
        private readonly Field $dayOfWeek,
    ) {
        $this->months = array_fill_keys($month->values, true);
        $this->domSpecials = $dayOfMonth->specialParts();
        $this->dowSpecials = $dayOfWeek->specialParts();
    }

    /**
     * Smallest run instant r with $from <= r < $limit, or null.
     */
    public function next(int $from, int $limit, DateTimeZone $timezone): ?int
    {
        $everyHour = $this->hour->matchesEveryValue();

        for ($windowStart = $from; $windowStart < $limit; $windowStart += self::WINDOW) {
            $windowEnd = min($windowStart + self::WINDOW, $limit);
            $points = self::offsetChanges($timezone, $windowStart - 2 * self::DAY, $windowEnd);
            $count = count($points);

            for ($i = 0; $i < $count; ++$i) {
                [$segmentStart, $offset] = $points[$i];
                $segmentEnd = $i + 1 < $count ? $points[$i + 1][0] : $windowEnd;
                $previousOffset = $i > 0 ? $points[$i - 1][1] : null;

                $lo = max($segmentStart, $windowStart);
                $hi = min($segmentEnd, $windowEnd);
                if ($lo >= $hi) {
                    continue;
                }

                // Spring forward: local times in the gap run at the transition instant.
                if ($previousOffset !== null && $offset > $previousOffset && !$everyHour && $segmentStart >= $windowStart) {
                    if ($this->nextWallTime($segmentStart + $previousOffset, $segmentStart + $offset) !== null) {
                        return $segmentStart;
                    }
                }

                $wallLo = $lo + $offset;
                // Fall back: skip the repeated local times unless the job runs every hour.
                if ($previousOffset !== null && $offset < $previousOffset && !$everyHour) {
                    $wallLo = max($wallLo, $segmentStart + $previousOffset);
                }

                $wall = $this->nextWallTime($wallLo, $hi + $offset);
                if ($wall !== null) {
                    return $wall - $offset;
                }
            }
        }

        return null;
    }

    /**
     * Largest run instant r with $lowerLimit <= r < $before, or null.
     */
    public function previous(int $before, int $lowerLimit, DateTimeZone $timezone): ?int
    {
        $span = 60;
        while (true) {
            $lo = max($before - $span, $lowerLimit);
            if ($this->next($lo, $before, $timezone) !== null) {
                // Binary search for the largest x in [$lo, $before) that still has a run in [x, $before).
                $low = $lo;
                $high = $before - 1;
                while ($low < $high) {
                    $mid = intdiv($low + $high + 1, 2);
                    if ($this->next($mid, $before, $timezone) !== null) {
                        $low = $mid;
                    } else {
                        $high = $mid - 1;
                    }
                }

                return $low;
            }
            if ($lo <= $lowerLimit) {
                return null;
            }
            $span *= 2;
        }
    }

    /**
     * Smallest matching wall-clock timestamp w with $wallLo <= w < $wallHi, or null.
     */
    public function nextWallTime(int $wallLo, int $wallHi): ?int
    {
        if ($wallLo >= $wallHi) {
            return null;
        }

        [$y, $m, $d, $h, $i, $s] = array_map(intval(...), explode(' ', gmdate('Y n j G i s', $wallLo)));

        while (true) {
            if (!isset($this->months[$m])) {
                $nextMonth = $this->month->nextValue($m);
                if ($nextMonth === null) {
                    ++$y;
                    $m = (int) $this->month->first();
                } else {
                    $m = $nextMonth;
                }
                $d = 1;
                $h = $i = $s = 0;
                if (self::wallTime($y, $m, 1) >= $wallHi) {
                    return null;
                }
                continue;
            }

            $daysInMonth = self::daysInMonth($y, $m);
            if ($d > $daysInMonth) {
                ++$m;
                if ($m > 12) {
                    $m = 1;
                    ++$y;
                }
                $d = 1;
                $h = $i = $s = 0;
                if (self::wallTime($y, $m, 1) >= $wallHi) {
                    return null;
                }
                continue;
            }

            if (self::wallTime($y, $m, $d) >= $wallHi) {
                return null;
            }

            if (!$this->dayMatches($y, $m, $d, $daysInMonth)) {
                ++$d;
                $h = $i = $s = 0;
                continue;
            }

            if (!$this->hour->contains($h)) {
                $nextHour = $this->hour->nextValue($h);
                if ($nextHour === null) {
                    ++$d;
                    $h = $i = $s = 0;
                    continue;
                }
                $h = $nextHour;
                $i = $s = 0;
            }

            if (!$this->minute->contains($i)) {
                $nextMinute = $this->minute->nextValue($i);
                if ($nextMinute === null) {
                    $i = $s = 0;
                    if (++$h > 23) {
                        $h = 0;
                        ++$d;
                    }
                    continue;
                }
                $i = $nextMinute;
                $s = 0;
            }

            if (!$this->second->contains($s)) {
                $nextSecond = $this->second->nextValue($s);
                if ($nextSecond === null) {
                    $s = 0;
                    if (++$i > 59) {
                        $i = 0;
                        if (++$h > 23) {
                            $h = 0;
                            ++$d;
                        }
                    }
                    continue;
                }
                $s = $nextSecond;
            }

            $wall = self::wallTime($y, $m, $d, $h, $i, $s);

            return $wall < $wallHi ? $wall : null;
        }
    }

    public function dayMatches(int $year, int $month, int $day, int $daysInMonth): bool
    {
        $weekday = self::weekday($year, $month, $day);
        $domMatch = $this->dayOfMonth->contains($day);
        if (!$domMatch) {
            foreach ($this->domSpecials as $part) {
                if (self::domSpecialMatches($part, $year, $month, $day, $daysInMonth)) {
                    $domMatch = true;
                    break;
                }
            }
        }

        $dowMatch = $this->dayOfWeek->contains($weekday);
        if (!$dowMatch) {
            foreach ($this->dowSpecials as $part) {
                $matches = match ($part->kind) {
                    PartKind::LastWeekdayOccurrence => $weekday === $part->start && $day + 7 > $daysInMonth,
                    PartKind::NthWeekday => $weekday === $part->start && intdiv($day - 1, 7) + 1 === $part->end,
                    default => false,
                };
                if ($matches) {
                    $dowMatch = true;
                    break;
                }
            }
        }

        // Vixie cron: if either day field starts with "*", both must match; otherwise either may match.
        if ($this->dayOfMonth->isWildcard() || $this->dayOfWeek->isWildcard()) {
            return $domMatch && $dowMatch;
        }

        return $domMatch || $dowMatch;
    }

    private static function domSpecialMatches(FieldPart $part, int $year, int $month, int $day, int $daysInMonth): bool
    {
        switch ($part->kind) {
            case PartKind::LastDayOfMonth:
                return $day === $daysInMonth - $part->start;

            case PartKind::LastWeekdayOfMonth:
                $last = $daysInMonth;
                $weekday = self::weekday($year, $month, $last);
                if ($weekday === 6) {
                    $last -= 1;
                } elseif ($weekday === 0) {
                    $last -= 2;
                }

                return $day === $last;

            case PartKind::NearestWeekday:
                $target = $part->start;
                if ($target > $daysInMonth) {
                    return false;
                }
                $weekday = self::weekday($year, $month, $target);
                if ($weekday === 6) {
                    $target = $target > 1 ? $target - 1 : $target + 2;
                } elseif ($weekday === 0) {
                    $target = $target < $daysInMonth ? $target + 1 : $target - 2;
                }

                return $day === $target;

            default:
                return false;
        }
    }

    /**
     * Seconds since 1970-01-01 00:00 for a proleptic Gregorian date/time (no time zone involved).
     */
    public static function wallTime(int $year, int $month, int $day, int $hour = 0, int $minute = 0, int $second = 0): int
    {
        // Howard Hinnant's days_from_civil algorithm.
        $y = $month <= 2 ? $year - 1 : $year;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        $days = $era * 146_097 + $doe - 719_468;

        return $days * self::DAY + $hour * 3600 + $minute * 60 + $second;
    }

    public static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0 ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /**
     * 0 (Sunday) through 6 (Saturday).
     */
    public static function weekday(int $year, int $month, int $day): int
    {
        $days = intdiv(self::wallTime($year, $month, $day), self::DAY);

        return (($days % 7) + 11) % 7;
    }

    /**
     * Offset changes in [$from, $to): list of [timestamp, offset], the first entry being the
     * offset in effect at $from.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function offsetChanges(DateTimeZone $timezone, int $from, int $to): array
    {
        $transitions = $timezone->getTransitions($from, $to);
        // getTransitions() returns false for fixed-offset zones such as "+03:00".
        // @phpstan-ignore identical.alwaysFalse
        if ($transitions === false || $transitions === []) {
            return [[$from, $timezone->getOffset(new \DateTimeImmutable('@' . $from))]];
        }

        $points = [];
        $last = null;
        foreach ($transitions as $transition) {
            $ts = max((int) $transition['ts'], $from);
            $offset = (int) $transition['offset'];
            if ($ts >= $to || $offset === $last) {
                continue;
            }
            if ($points !== [] && $points[count($points) - 1][0] === $ts) {
                array_pop($points);
            }
            $points[] = [$ts, $offset];
            $last = $offset;
        }

        return $points === [] ? [[$from, $timezone->getOffset(new \DateTimeImmutable('@' . $from))]] : $points;
    }
}
