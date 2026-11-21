<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit;

/**
 * One comma-separated item of a cron field, e.g. `1-5`, `*\/15`, `L`, `5#3`.
 *
 * Meaning of `$start`/`$end` per kind:
 *  - All:                   field min / field max
 *  - Value:                 value / value (or field max when a step is given)
 *  - Range:                 range start / range end (start > end means a wrap-around range)
 *  - LastDayOfMonth:        offset n of `L-n` (0 for `L`)
 *  - NearestWeekday:        the day n of `nW`
 *  - LastWeekdayOccurrence: weekday of `nL`
 *  - NthWeekday:            weekday / occurrence k of `n#k`
 */
final readonly class FieldPart
{
    public function __construct(
        public PartKind $kind,
        public int $start = 0,
        public int $end = 0,
        public int $step = 1,
        public string $source = '',
    ) {}

    public function hasStep(): bool
    {
        return $this->step > 1;
    }
}
