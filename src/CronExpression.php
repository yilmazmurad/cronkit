<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use Stringable;
use YilmazMurad\Cronkit\Exception\InvalidExpressionException;
use YilmazMurad\Cronkit\Exception\NoRunFoundException;
use YilmazMurad\Cronkit\Internal\Parser;
use YilmazMurad\Cronkit\Internal\RunFinder;

/**
 * An immutable, parsed cron expression.
 *
 * ```php
 * $cron = CronExpression::parse('30 9 * * MON-FRI');
 * $cron->isDue(new DateTimeImmutable('2026-11-23 09:30', new DateTimeZone('Europe/Berlin'))); // true
 * $cron->nextRun(new DateTimeImmutable('now'));
 * echo $cron->describe(); // "At 09:30, Monday through Friday"
 * ```
 */
final readonly class CronExpression implements Stringable
{
    /** How far nextRun()/previousRun() search before giving up. */
    public const int SEARCH_HORIZON_YEARS = 100;

    private const int HORIZON_SECONDS = self::SEARCH_HORIZON_YEARS * 366 * 86_400;

    public Field $second;
    public Field $minute;
    public Field $hour;
    public Field $dayOfMonth;
    public Field $month;
    public Field $dayOfWeek;

    private RunFinder $finder;

    private function __construct(
        public string $expression,
        public bool $withSeconds,
    ) {
        $fields = Parser::parse($expression, $withSeconds);
        $this->second = $fields['second'];
        $this->minute = $fields['minute'];
        $this->hour = $fields['hour'];
        $this->dayOfMonth = $fields['dayOfMonth'];
        $this->month = $fields['month'];
        $this->dayOfWeek = $fields['dayOfWeek'];
        $this->finder = new RunFinder($this->second, $this->minute, $this->hour, $this->dayOfMonth, $this->month, $this->dayOfWeek);
    }

    /**
     * Parses a 5-field expression (`minute hour day-of-month month day-of-week`), a macro such as
     * `@daily`, or – with $withSeconds – a 6-field expression with a leading seconds field.
     *
     * @throws InvalidExpressionException
     */
    public static function parse(string $expression, bool $withSeconds = false): self
    {
        return new self($expression, $withSeconds);
    }

    public static function isValid(string $expression, bool $withSeconds = false): bool
    {
        try {
            Parser::parse($expression, $withSeconds);

            return true;
        } catch (InvalidExpressionException) {
            return false;
        }
    }

    public function getField(FieldType $type): Field
    {
        return match ($type) {
            FieldType::Second => $this->second,
            FieldType::Minute => $this->minute,
            FieldType::Hour => $this->hour,
            FieldType::DayOfMonth => $this->dayOfMonth,
            FieldType::Month => $this->month,
            FieldType::DayOfWeek => $this->dayOfWeek,
        };
    }

    /**
     * Whether the expression runs at the given moment (default: now). Without the seconds field the
     * comparison is done at minute precision. The moment's own time zone is used unless $timezone is given.
     */
    public function isDue(?DateTimeInterface $at = null, ?DateTimeZone $timezone = null): bool
    {
        $timezone = self::resolveTimezone($at, $timezone);
        $at = self::toImmutable($at, $timezone);
        $timestamp = $at->getTimestamp();
        if (!$this->withSeconds) {
            $timestamp -= (int) $at->format('s');
        }

        return $this->finder->next($timestamp, $timestamp + 1, $timezone) === $timestamp;
    }

    /**
     * The first run strictly after $from (or at $from when $inclusive is true).
     *
     * @throws NoRunFoundException when no run exists within {@see self::SEARCH_HORIZON_YEARS} years
     */
    public function nextRun(?DateTimeInterface $from = null, bool $inclusive = false, ?DateTimeZone $timezone = null): DateTimeImmutable
    {
        $timezone = self::resolveTimezone($from, $timezone);
        $from = self::toImmutable($from, $timezone);
        $start = $from->getTimestamp();
        if (!$inclusive || (int) $from->format('u') > 0) {
            ++$start;
        }

        $run = $this->finder->next($start, $start + self::HORIZON_SECONDS, $timezone);
        if ($run === null) {
            throw new NoRunFoundException(sprintf(
                'Cron expression "%s" has no run within %d years after %s',
                $this->expression,
                self::SEARCH_HORIZON_YEARS,
                $from->format(DateTimeInterface::ATOM),
            ));
        }

        return self::fromTimestamp($run, $timezone);
    }

    /**
     * The last run strictly before $from (or at $from when $inclusive is true).
     *
     * @throws NoRunFoundException when no run exists within {@see self::SEARCH_HORIZON_YEARS} years
     */
    public function previousRun(?DateTimeInterface $from = null, bool $inclusive = false, ?DateTimeZone $timezone = null): DateTimeImmutable
    {
        $timezone = self::resolveTimezone($from, $timezone);
        $from = self::toImmutable($from, $timezone);
        $before = $from->getTimestamp();
        if ($inclusive || (int) $from->format('u') > 0) {
            ++$before;
        }

        $run = $this->finder->previous($before, $before - self::HORIZON_SECONDS, $timezone);
        if ($run === null) {
            throw new NoRunFoundException(sprintf(
                'Cron expression "%s" has no run within %d years before %s',
                $this->expression,
                self::SEARCH_HORIZON_YEARS,
                $from->format(DateTimeInterface::ATOM),
            ));
        }

        return self::fromTimestamp($run, $timezone);
    }

    /**
     * Lazily yields the next $count runs after $from.
     *
     * @return Generator<int, DateTimeImmutable>
     */
    public function nextRuns(int $count, ?DateTimeInterface $from = null, bool $inclusive = false, ?DateTimeZone $timezone = null): Generator
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Count must not be negative.');
        }
        $timezone = self::resolveTimezone($from, $timezone);
        $cursor = self::toImmutable($from, $timezone);
        for ($i = 0; $i < $count; ++$i) {
            $cursor = $this->nextRun($cursor, $inclusive && $i === 0, $timezone);
            yield $i => $cursor;
        }
    }

    /**
     * Lazily yields the previous $count runs before $from, newest first.
     *
     * @return Generator<int, DateTimeImmutable>
     */
    public function previousRuns(int $count, ?DateTimeInterface $from = null, bool $inclusive = false, ?DateTimeZone $timezone = null): Generator
    {
        if ($count < 0) {
            throw new InvalidArgumentException('Count must not be negative.');
        }
        $timezone = self::resolveTimezone($from, $timezone);
        $cursor = self::toImmutable($from, $timezone);
        for ($i = 0; $i < $count; ++$i) {
            $cursor = $this->previousRun($cursor, $inclusive && $i === 0, $timezone);
            yield $i => $cursor;
        }
    }

    /**
     * A human-readable English description, e.g. "At 09:30, Monday through Friday".
     */
    public function describe(): string
    {
        return (new Describer())->describe($this);
    }

    /**
     * The normalised expression (macros expanded, whitespace collapsed).
     */
    public function toString(): string
    {
        $fields = [$this->minute, $this->hour, $this->dayOfMonth, $this->month, $this->dayOfWeek];
        if ($this->withSeconds) {
            array_unshift($fields, $this->second);
        }

        return implode(' ', array_map(static fn(Field $f): string => $f->source, $fields));
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private static function resolveTimezone(?DateTimeInterface $moment, ?DateTimeZone $timezone): DateTimeZone
    {
        if ($timezone !== null) {
            return $timezone;
        }
        if ($moment !== null) {
            return $moment->getTimezone();
        }

        return new DateTimeZone(date_default_timezone_get());
    }

    private static function toImmutable(?DateTimeInterface $moment, DateTimeZone $timezone): DateTimeImmutable
    {
        if ($moment === null) {
            return new DateTimeImmutable('now', $timezone);
        }

        return DateTimeImmutable::createFromInterface($moment)->setTimezone($timezone);
    }

    private static function fromTimestamp(int $timestamp, DateTimeZone $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    }
}
