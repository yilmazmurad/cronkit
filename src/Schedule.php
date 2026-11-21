<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * A small registry of named cron jobs.
 *
 * ```php
 * $schedule = (new Schedule())
 *     ->add('backup', '0 3 * * *', fn () => backup())
 *     ->add('report', '@weekly', 'app:report');
 *
 * foreach ($schedule->dueJobs(new DateTimeImmutable()) as $job) {
 *     ($job->payload)();
 * }
 * ```
 *
 * @implements IteratorAggregate<string, ScheduledJob<mixed>>
 */
final class Schedule implements Countable, IteratorAggregate
{
    /** @var array<string, ScheduledJob<mixed>> */
    private array $jobs = [];

    /**
     * @throws InvalidArgumentException when a job with the same name already exists
     */
    public function add(string $name, CronExpression|string $expression, mixed $payload = null, ?DateTimeZone $timezone = null): self
    {
        if (isset($this->jobs[$name])) {
            throw new InvalidArgumentException(sprintf('A job named "%s" is already scheduled.', $name));
        }
        if (is_string($expression)) {
            $expression = CronExpression::parse($expression);
        }
        $this->jobs[$name] = new ScheduledJob($name, $expression, $payload, $timezone);

        return $this;
    }

    public function remove(string $name): self
    {
        unset($this->jobs[$name]);

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->jobs[$name]);
    }

    /**
     * @return ScheduledJob<mixed>|null
     */
    public function get(string $name): ?ScheduledJob
    {
        return $this->jobs[$name] ?? null;
    }

    /**
     * Jobs that are due at the given moment (default: now), in registration order.
     *
     * @return list<ScheduledJob<mixed>>
     */
    public function dueJobs(?DateTimeInterface $at = null): array
    {
        $at ??= new DateTimeImmutable();

        return array_values(array_filter($this->jobs, static fn(ScheduledJob $job): bool => $job->isDue($at)));
    }

    /**
     * The next run of every job after $from, sorted by time.
     *
     * @return array<string, DateTimeImmutable>
     */
    public function upcoming(?DateTimeInterface $from = null): array
    {
        $from ??= new DateTimeImmutable();
        $runs = array_map(static fn(ScheduledJob $job): DateTimeImmutable => $job->nextRun($from), $this->jobs);
        asort($runs);

        return $runs;
    }

    public function count(): int
    {
        return count($this->jobs);
    }

    /**
     * @return ArrayIterator<string, ScheduledJob<mixed>>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->jobs);
    }
}
