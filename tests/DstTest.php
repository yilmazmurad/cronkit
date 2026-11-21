<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YilmazMurad\Cronkit\CronExpression;

/**
 * Daylight saving time behaviour.
 *
 * America/New_York 2026: spring forward 2026-03-08 02:00 EST -> 03:00 EDT, fall back 2026-11-01 02:00 EDT -> 01:00 EST.
 * Europe/Berlin 2026:    spring forward 2026-03-29 02:00 CET -> 03:00 CEST, fall back 2026-10-25 03:00 CEST -> 02:00 CET.
 */
final class DstTest extends TestCase
{
    private const string FORMAT = 'Y-m-d H:i:s T';

    private static function at(string $time, string $zone): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone($zone));
    }

    /**
     * @return list<string>
     */
    private static function runs(string $expression, string $from, string $zone, int $count, bool $withSeconds = false): array
    {
        $runs = CronExpression::parse($expression, $withSeconds)->nextRuns($count, self::at($from, $zone));

        return array_values(array_map(static fn(DateTimeImmutable $d): string => $d->format(self::FORMAT), iterator_to_array($runs)));
    }

    public function testSpringForwardFixedTimeRunsAtTransition(): void
    {
        self::assertSame(
            ['2026-03-07 02:30:00 EST', '2026-03-08 03:00:00 EDT', '2026-03-09 02:30:00 EDT'],
            self::runs('30 2 * * *', '2026-03-07 00:00', 'America/New_York', 3),
        );
    }

    public function testSpringForwardWildcardHourSkipsMissingTimes(): void
    {
        self::assertSame(
            ['2026-03-08 01:00:00 EST', '2026-03-08 01:30:00 EST', '2026-03-08 03:00:00 EDT', '2026-03-08 03:30:00 EDT'],
            self::runs('*/30 * * * *', '2026-03-08 00:45', 'America/New_York', 4),
        );
        self::assertSame(
            ['2026-03-08 01:45:00 EST', '2026-03-08 03:45:00 EDT'],
            self::runs('45 * * * *', '2026-03-08 01:00', 'America/New_York', 2),
        );
    }

    public function testSpringForwardSeveralMissingTimesRunOnce(): void
    {
        self::assertSame(
            ['2026-03-08 01:45:00 EST', '2026-03-08 03:00:00 EDT', '2026-03-08 03:15:00 EDT', '2026-03-08 03:45:00 EDT'],
            self::runs('15,45 1-3 * * *', '2026-03-08 01:30', 'America/New_York', 4),
        );
    }

    public function testSpringForwardHourlyRunsKeepOneHourSpacing(): void
    {
        $runs = iterator_to_array(CronExpression::parse('0 * * * *')->nextRuns(6, self::at('2026-03-07 23:30', 'America/New_York')));

        self::assertSame('2026-03-08 03:00:00 EDT', $runs[2]->format(self::FORMAT));
        for ($i = 1; $i < count($runs); ++$i) {
            self::assertSame(3600, $runs[$i]->getTimestamp() - $runs[$i - 1]->getTimestamp());
        }
    }

    public function testFallBackFixedTimeRunsOnce(): void
    {
        self::assertSame(
            ['2026-10-31 01:30:00 EDT', '2026-11-01 01:30:00 EDT', '2026-11-02 01:30:00 EST'],
            self::runs('30 1 * * *', '2026-10-31 00:00', 'America/New_York', 3),
        );
    }

    public function testFallBackFromInsideSecondOccurrence(): void
    {
        // 01:15 EST is the second 01:15 of the day; 01:30 already ran as EDT.
        $from = (new DateTimeImmutable('2026-11-01 06:15:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/New_York'));
        self::assertSame('2026-11-01 01:15:00 EST', $from->format(self::FORMAT));

        $next = CronExpression::parse('30 1 * * *')->nextRun($from);
        self::assertSame('2026-11-02 01:30:00 EST', $next->format(self::FORMAT));
    }

    public function testFallBackWildcardHourRunsInBothOccurrences(): void
    {
        self::assertSame(
            [
                '2026-11-01 01:00:00 EDT', '2026-11-01 01:30:00 EDT',
                '2026-11-01 01:00:00 EST', '2026-11-01 01:30:00 EST',
                '2026-11-01 02:00:00 EST',
            ],
            self::runs('*/30 * * * *', '2026-11-01 00:45', 'America/New_York', 5),
        );
    }

    public function testFallBackHourlyRunsKeepOneHourSpacing(): void
    {
        $runs = iterator_to_array(CronExpression::parse('@hourly')->nextRuns(6, self::at('2026-10-31 23:30', 'America/New_York')));

        for ($i = 1; $i < count($runs); ++$i) {
            self::assertSame(3600, $runs[$i]->getTimestamp() - $runs[$i - 1]->getTimestamp());
        }
    }

    public function testPreviousRunAcrossFallBack(): void
    {
        $cron = CronExpression::parse('0 * * * *');
        $from = self::at('2026-11-01 02:00', 'America/New_York');

        $first = $cron->previousRun($from);
        $second = $cron->previousRun($first);
        self::assertSame('2026-11-01 01:00:00 EST', $first->format(self::FORMAT));
        self::assertSame('2026-11-01 01:00:00 EDT', $second->format(self::FORMAT));

        self::assertSame(
            '2026-11-01 01:30:00 EDT',
            CronExpression::parse('30 1 * * *')->previousRun(self::at('2026-11-01 04:00', 'America/New_York'))->format(self::FORMAT),
        );
    }

    public function testPreviousRunAcrossSpringForward(): void
    {
        self::assertSame(
            '2026-03-08 03:00:00 EDT',
            CronExpression::parse('30 2 * * *')->previousRun(self::at('2026-03-08 04:00', 'America/New_York'))->format(self::FORMAT),
        );
    }

    public function testIsDueAroundDst(): void
    {
        $ny = 'America/New_York';
        self::assertTrue(CronExpression::parse('30 2 * * *')->isDue(self::at('2026-03-08 03:00', $ny)));
        self::assertFalse(CronExpression::parse('45 * * * *')->isDue(self::at('2026-03-08 03:00', $ny)));

        $firstOneThirty = (new DateTimeImmutable('2026-11-01 05:30:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($ny));
        $secondOneThirty = (new DateTimeImmutable('2026-11-01 06:30:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($ny));
        self::assertTrue(CronExpression::parse('30 1 * * *')->isDue($firstOneThirty));
        self::assertFalse(CronExpression::parse('30 1 * * *')->isDue($secondOneThirty));
        self::assertTrue(CronExpression::parse('30 * * * *')->isDue($firstOneThirty));
        self::assertTrue(CronExpression::parse('30 * * * *')->isDue($secondOneThirty));
    }

    public function testBerlinSpringForward(): void
    {
        self::assertSame(
            ['2026-03-28 02:30:00 CET', '2026-03-29 03:00:00 CEST', '2026-03-30 02:30:00 CEST'],
            self::runs('30 2 * * *', '2026-03-28 00:00', 'Europe/Berlin', 3),
        );
    }

    public function testBerlinFallBack(): void
    {
        self::assertSame(
            ['2026-10-25 02:00:00 CEST', '2026-10-25 02:20:00 CEST', '2026-10-25 02:40:00 CEST', '2026-10-26 02:00:00 CET'],
            self::runs('*/20 2 * * *', '2026-10-25 00:00', 'Europe/Berlin', 4),
        );
        self::assertSame(
            ['2026-10-25 02:30:00 CEST', '2026-10-25 02:30:00 CET', '2026-10-25 03:30:00 CET'],
            self::runs('30 * * * *', '2026-10-25 01:50', 'Europe/Berlin', 3),
        );
    }

    public function testSecondsFieldInGap(): void
    {
        self::assertSame(
            ['2026-03-29 03:00:00 CEST', '2026-03-30 02:30:15 CEST'],
            self::runs('15 30 2 * * *', '2026-03-29 00:00', 'Europe/Berlin', 2, true),
        );
    }

    public function testHalfHourDstShift(): void
    {
        // Australia/Lord_Howe moves from +10:30 to +11:00 on 2026-10-04 at 02:00 local time.
        $runs = CronExpression::parse('15 2 * * *')->nextRuns(2, self::at('2026-10-04 00:00', 'Australia/Lord_Howe'));
        $formatted = array_map(static fn(DateTimeImmutable $d): string => $d->format('Y-m-d H:i P'), iterator_to_array($runs));

        self::assertSame(['2026-10-04 02:30 +11:00', '2026-10-05 02:15 +11:00'], $formatted);
    }

    public function testSameInstantDifferentZones(): void
    {
        $from = new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC'));
        $cron = CronExpression::parse('0 18 * * *');

        self::assertSame('2026-06-15 16:00:00', $cron->nextRun($from, false, new DateTimeZone('Europe/Berlin'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-15 22:00:00', $cron->nextRun($from, false, new DateTimeZone('America/New_York'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    /**
     * Consistency between nextRun(), previousRun() and isDue() across DST weeks.
     */
    #[DataProvider('consistencyProvider')]
    public function testConsistencyAcrossTransitions(string $expression, string $zone, string $from): void
    {
        $cron = CronExpression::parse($expression);
        $runs = iterator_to_array($cron->nextRuns(60, self::at($from, $zone)));

        for ($i = 0; $i < count($runs); ++$i) {
            self::assertTrue($cron->isDue($runs[$i]), 'isDue ' . $runs[$i]->format(self::FORMAT));
            if ($i > 0) {
                self::assertGreaterThan($runs[$i - 1], $runs[$i]);
                self::assertEquals($runs[$i - 1], $cron->previousRun($runs[$i]), 'previousRun of ' . $runs[$i]->format(self::FORMAT));
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function consistencyProvider(): iterable
    {
        $cases = [
            ['America/New_York', '2026-03-07 20:00'],
            ['America/New_York', '2026-10-31 20:00'],
            ['Europe/Berlin', '2026-03-28 20:00'],
            ['Europe/Berlin', '2026-10-24 20:00'],
        ];
        foreach (['*/20 * * * *', '0,30 1-3 * * *', '15 2 * * *', '0 */2 * * *', '*/10 2 * * *'] as $expression) {
            foreach ($cases as [$zone, $from]) {
                yield "$expression @ $zone $from" => [$expression, $zone, $from];
            }
        }
    }
}
