<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit;

/**
 * Kind of a single comma-separated item inside a cron field.
 */
enum PartKind: string
{
    /** `*` or `?`, optionally with a step (`*\/5`). */
    case All = 'all';
    /** A single value (`5`, `MON`), optionally with a step (`5/15`). */
    case Value = 'value';
    /** A range (`1-5`, `MON-FRI`), optionally with a step (`0-30/10`). */
    case Range = 'range';
    /** Day-of-month `L` or `L-n`: the last day of the month (minus n days). */
    case LastDayOfMonth = 'last-day-of-month';
    /** Day-of-month `LW`: the last weekday (Mon-Fri) of the month. */
    case LastWeekdayOfMonth = 'last-weekday-of-month';
    /** Day-of-month `nW`: the weekday (Mon-Fri) nearest to day n, within the same month. */
    case NearestWeekday = 'nearest-weekday';
    /** Day-of-week `nL`: the last given weekday of the month. */
    case LastWeekdayOccurrence = 'last-weekday-occurrence';
    /** Day-of-week `n#k`: the k-th given weekday of the month. */
    case NthWeekday = 'nth-weekday';
}
