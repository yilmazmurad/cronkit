<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A named cron job registered in a {@see Schedule}.
 *
 * @template TPayload
 */
final readonly class ScheduledJob
{
    /**
     * @param TPayload $payload Anything you need to run the job (callable, command name, DTO, ...).
     * @param DateTimeZone|null $timezone Zone the expression is evaluated in (null: the zone of the given moment).
     */
    public function __construct(
        public string $name,
        public CronExpression $expression,
        public mixed $payload = null,
        public ?DateTimeZone $timezone = null,
    ) {}

    public function isDue(DateTimeInterface $at): bool
    {
        return $this->expression->isDue($at, $this->timezone);
    }

    public function nextRun(DateTimeInterface $from): DateTimeImmutable
    {
        return $this->expression->nextRun($from, false, $this->timezone);
    }
}
