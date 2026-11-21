<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit;

/**
 * The individual fields of a cron expression.
 */
enum FieldType: string
{
    case Second = 'second';
    case Minute = 'minute';
    case Hour = 'hour';
    case DayOfMonth = 'day-of-month';
    case Month = 'month';
    case DayOfWeek = 'day-of-week';

    public function min(): int
    {
        return match ($this) {
            self::DayOfMonth, self::Month => 1,
            default => 0,
        };
    }

    /**
     * Highest value accepted while parsing (day-of-week accepts 7 as an alias for Sunday).
     */
    public function max(): int
    {
        return match ($this) {
            self::Second, self::Minute => 59,
            self::Hour => 23,
            self::DayOfMonth => 31,
            self::Month => 12,
            self::DayOfWeek => 7,
        };
    }

    /**
     * Three-letter names that may be used instead of numbers.
     *
     * @return array<string, int>
     */
    public function names(): array
    {
        return match ($this) {
            self::Month => [
                'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
                'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
            ],
            self::DayOfWeek => [
                'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
            ],
            default => [],
        };
    }
}
