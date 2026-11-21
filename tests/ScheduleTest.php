<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use YilmazMurad\Cronkit\CronExpression;
use YilmazMurad\Cronkit\Exception\InvalidExpressionException;
use YilmazMurad\Cronkit\Schedule;
use YilmazMurad\Cronkit\ScheduledJob;

final class ScheduleTest extends TestCase
{
    private function schedule(): Schedule
    {
        return (new Schedule())
            ->add('every-minute', '* * * * *', 'a')
            ->add('backup', '0 3 * * *', 'b')
            ->add('report', CronExpression::parse('0 9 * * MON'), 'c')
            ->add('tokyo-morning', '0 9 * * *', 'd', new DateTimeZone('Asia/Tokyo'));
    }

    public function testDueJobs(): void
    {
        $schedule = $this->schedule();

        $at = new DateTimeImmutable('2026-11-23 09:00:00', new DateTimeZone('UTC')); // Monday
        $names = array_map(static fn(ScheduledJob $j): string => $j->name, $schedule->dueJobs($at));
        self::assertSame(['every-minute', 'report'], $names);

        $at = new DateTimeImmutable('2026-11-23 00:00:00', new DateTimeZone('UTC')); // 09:00 in Tokyo
        $names = array_map(static fn(ScheduledJob $j): string => $j->name, $schedule->dueJobs($at));
        self::assertSame(['every-minute', 'tokyo-morning'], $names);
    }

    public function testDueJobsDefaultsToNow(): void
    {
        $due = (new Schedule())->add('always', '* * * * *')->dueJobs();

        self::assertCount(1, $due);
    }

    public function testPayloadIsKept(): void
    {
        $job = $this->schedule()->get('backup');

        self::assertNotNull($job);
        self::assertSame('b', $job->payload);
        self::assertSame('0 3 * * *', $job->expression->toString());
    }

    public function testUpcomingIsSorted(): void
    {
        $from = new DateTimeImmutable('2026-11-22 23:30:00', new DateTimeZone('UTC')); // Sunday
        $upcoming = $this->schedule()->upcoming($from);

        self::assertSame(['every-minute', 'tokyo-morning', 'backup', 'report'], array_keys($upcoming));
        self::assertSame('2026-11-22 23:31', $upcoming['every-minute']->format('Y-m-d H:i'));
        self::assertSame('2026-11-23 09:00 +09:00', $upcoming['tokyo-morning']->format('Y-m-d H:i P'));
        self::assertSame('2026-11-23 03:00', $upcoming['backup']->format('Y-m-d H:i'));
        self::assertSame('2026-11-23 09:00', $upcoming['report']->format('Y-m-d H:i'));
    }

    public function testRegistryOperations(): void
    {
        $schedule = $this->schedule();

        self::assertCount(4, $schedule);
        self::assertTrue($schedule->has('backup'));
        $schedule->remove('backup');
        self::assertFalse($schedule->has('backup'));
        self::assertNull($schedule->get('backup'));
        self::assertSame(['every-minute', 'report', 'tokyo-morning'], array_keys(iterator_to_array($schedule)));
    }

    public function testDuplicateNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A job named "backup" is already scheduled.');

        $this->schedule()->add('backup', '@daily');
    }

    public function testInvalidExpressionThrows(): void
    {
        $this->expectException(InvalidExpressionException::class);

        (new Schedule())->add('broken', '61 * * * *');
    }
}
