<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit;

/**
 * A parsed cron field: its parts plus a pre-computed lookup of the plain values it allows.
 *
 * Special day parts (`L`, `W`, `#`) cannot be expanded statically; they are evaluated
 * per month by {@see CronExpression}.
 */
final readonly class Field
{
    /** @var list<int> Sorted plain values matched by this field (specials excluded). */
    public array $values;

    /** @var array<int, true> */
    private array $lookup;

    /** @var array<int, int|null> Smallest allowed value >= key. */
    private array $next;

    /**
     * @param list<FieldPart> $parts
     */
    public function __construct(
        public FieldType $type,
        public string $source,
        public array $parts,
    ) {
        $maxValue = $type === FieldType::DayOfWeek ? 6 : $type->max();
        $lookup = [];
        foreach ($parts as $part) {
            foreach (self::expand($type, $part) as $value) {
                $lookup[$value] = true;
            }
        }
        ksort($lookup);
        $this->lookup = $lookup;
        $this->values = array_keys($lookup);

        $next = [];
        $candidate = null;
        for ($v = $maxValue; $v >= $type->min(); --$v) {
            if (isset($lookup[$v])) {
                $candidate = $v;
            }
            $next[$v] = $candidate;
        }
        $this->next = $next;
    }

    /**
     * Whether the field begins with `*` or `?` (Vixie cron's rule for the day-of-month /
     * day-of-week AND/OR decision).
     */
    public function isWildcard(): bool
    {
        return $this->source !== '' && ($this->source[0] === '*' || $this->source[0] === '?');
    }

    /**
     * Whether the field is a single unrestricted `*` (or `?`).
     */
    public function isAll(): bool
    {
        return count($this->parts) === 1
            && $this->parts[0]->kind === PartKind::All
            && !$this->parts[0]->hasStep();
    }

    /**
     * Whether every possible value of the field is matched.
     */
    public function matchesEveryValue(): bool
    {
        $maxValue = $this->type === FieldType::DayOfWeek ? 6 : $this->type->max();

        return count($this->values) === $maxValue - $this->type->min() + 1;
    }

    public function contains(int $value): bool
    {
        return isset($this->lookup[$value]);
    }

    /**
     * Smallest plain value >= $value, or null when none is left.
     */
    public function nextValue(int $value): ?int
    {
        return $this->next[$value] ?? null;
    }

    public function first(): ?int
    {
        return $this->values[0] ?? null;
    }

    /**
     * @return list<FieldPart>
     */
    public function specialParts(): array
    {
        return array_values(array_filter(
            $this->parts,
            static fn(FieldPart $p): bool => !in_array($p->kind, [PartKind::All, PartKind::Value, PartKind::Range], true),
        ));
    }

    /**
     * @return list<int>
     */
    private static function expand(FieldType $type, FieldPart $part): array
    {
        if (!in_array($part->kind, [PartKind::All, PartKind::Value, PartKind::Range], true)) {
            return [];
        }

        $min = $type->min();
        $max = $type->max();
        if ($type === FieldType::DayOfWeek && ($part->kind === PartKind::All || $part->start > $part->end)) {
            $max = 6;
        }

        if ($part->start <= $part->end) {
            $sequence = range($part->start, $part->end);
        } else {
            // Wrap-around range such as FRI-MON or 22-2.
            $sequence = [...range($part->start, $max), ...range($min, $part->end)];
        }

        $values = [];
        foreach ($sequence as $i => $value) {
            if ($i % $part->step === 0) {
                $values[] = ($type === FieldType::DayOfWeek && $value === 7) ? 0 : $value;
            }
        }

        return $values;
    }
}
