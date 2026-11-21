<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit\Tests;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YilmazMurad\Cronkit\CronExpression;
use YilmazMurad\Cronkit\Exception\NoRunFoundException;

final class NextRunTest extends TestCase
{
    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    #[DataProvider('nextRunProvider')]
    public function testNextRun(string $expression, string $from, string $expected): void
    {
        $next = CronExpression::parse($expression)->nextRun(self::utc($from));

        self::assertSame($expected, $next->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function nextRunProvider(): iterable
    {
        // 2026-11-21 is a Saturday.
        yield 'every minute' => ['* * * * *', '2026-11-21 10:15:30', '2026-11-21 10:16:00'];
        yield 'every minute exact' => ['* * * * *', '2026-11-21 10:15:00', '2026-11-21 10:16:00'];
        yield 'every 5 minutes' => ['*/5 * * * *', '2026-11-21 10:16:00', '2026-11-21 10:20:00'];
        yield 'hour rollover' => ['*/5 * * * *', '2026-11-21 10:56:00', '2026-11-21 11:00:00'];
        yield 'daily' => ['0 0 * * *', '2026-11-21 10:00:00', '2026-11-22 00:00:00'];
        yield 'year rollover' => ['0 0 1 1 *', '2026-11-21 10:00:00', '2027-01-01 00:00:00'];
        yield 'month rollover' => ['30 8 1 * *', '2026-11-21 10:00:00', '2026-12-01 08:30:00'];
        yield 'specific weekday' => ['0 9 * * MON', '2026-11-21 10:00:00', '2026-11-23 09:00:00'];
        yield 'weekday range from saturday' => ['0 9 * * 1-5', '2026-11-21 10:00:00', '2026-11-23 09:00:00'];
        yield 'sunday as 7' => ['0 9 * * 7', '2026-11-21 10:00:00', '2026-11-22 09:00:00'];
        yield 'same day later hour' => ['0 18 * * *', '2026-11-21 10:00:00', '2026-11-21 18:00:00'];
        yield 'list of hours' => ['15 6,12,18 * * *', '2026-11-21 12:15:00', '2026-11-21 18:15:00'];
        yield 'day 31 skips short months' => ['0 0 31 * *', '2026-11-21 00:00:00', '2026-12-31 00:00:00'];
        yield 'day 31 skips february' => ['0 0 31 * *', '2027-01-31 00:00:00', '2027-03-31 00:00:00'];
        yield 'leap day' => ['0 0 29 2 *', '2026-11-21 00:00:00', '2028-02-29 00:00:00'];
        yield 'leap day skips 2100' => ['0 0 29 2 *', '2096-03-01 00:00:00', '2104-02-29 00:00:00'];
        yield 'month names' => ['0 0 1 MAR,SEP *', '2026-11-21 00:00:00', '2027-03-01 00:00:00'];
        yield 'wrap-around hours' => ['0 22-2 * * *', '2026-11-21 02:30:00', '2026-11-21 22:00:00'];
        yield 'wrap-around hours after midnight' => ['0 22-2 * * *', '2026-11-21 23:30:00', '2026-11-22 00:00:00'];
        yield 'step from value' => ['10/20 * * * *', '2026-11-21 10:31:00', '2026-11-21 10:50:00'];
        // Vixie semantics: both day fields restricted => OR.
        yield 'dom or dow (dow first)' => ['0 0 15 * MON', '2026-11-21 00:00:00', '2026-11-23 00:00:00'];
        yield 'dom or dow (dom first)' => ['0 0 22 * MON', '2026-11-21 00:00:00', '2026-11-22 00:00:00'];
        yield 'dom with star dow => and' => ['0 0 15 * *', '2026-11-21 00:00:00', '2026-12-15 00:00:00'];
        yield 'starred step dom => and' => ['0 0 */2 * MON', '2026-11-21 00:00:00', '2026-11-23 00:00:00'];
        yield 'starred step dom => and (skips)' => ['0 0 */2 * TUE', '2026-11-21 00:00:00', '2026-12-01 00:00:00'];
        yield 'question mark dom => and' => ['0 0 ? * TUE', '2026-11-21 00:00:00', '2026-11-24 00:00:00'];
        // Special characters.
        yield 'last day of month' => ['0 0 L * *', '2026-11-21 00:00:00', '2026-11-30 00:00:00'];
        yield 'last day of february' => ['0 0 L 2 *', '2026-11-21 00:00:00', '2027-02-28 00:00:00'];
        yield 'last day of leap february' => ['0 0 L 2 *', '2027-03-01 00:00:00', '2028-02-29 00:00:00'];
        yield 'L-2' => ['0 0 L-2 * *', '2026-11-21 00:00:00', '2026-11-28 00:00:00'];
        yield 'last weekday (month ends sunday)' => ['0 0 LW * *', '2026-05-01 00:00:00', '2026-05-29 00:00:00'];
        yield 'last weekday (month ends monday)' => ['0 0 LW * *', '2026-11-21 00:00:00', '2026-11-30 00:00:00'];
        yield 'nearest weekday saturday -> friday' => ['0 0 15W * *', '2026-08-01 00:00:00', '2026-08-14 00:00:00'];
        yield 'nearest weekday sunday -> monday' => ['0 0 15W * *', '2026-11-01 00:00:00', '2026-11-16 00:00:00'];
        yield 'nearest weekday 1st saturday -> monday 3rd' => ['0 0 1W * *', '2026-07-31 00:00:00', '2026-08-03 00:00:00'];
        yield 'nearest weekday 31st sunday -> friday 29th' => ['0 0 31W * *', '2026-05-01 00:00:00', '2026-05-29 00:00:00'];
        yield 'nearest weekday on weekday' => ['0 0 10W * *', '2026-11-01 00:00:00', '2026-11-10 00:00:00'];
        yield 'last friday' => ['0 0 * * 5L', '2026-11-21 00:00:00', '2026-11-27 00:00:00'];
        yield 'last sunday' => ['0 0 * * SUNL', '2026-11-30 00:00:00', '2026-12-27 00:00:00'];
        yield 'third friday' => ['0 0 * * 5#3', '2026-11-21 00:00:00', '2026-12-18 00:00:00'];
        yield 'first monday' => ['0 0 * * MON#1', '2026-11-21 00:00:00', '2026-12-07 00:00:00'];
        yield 'fifth monday' => ['0 0 * * 1#5', '2026-11-21 00:00:00', '2026-11-30 00:00:00'];
        yield 'fifth saturday skips months' => ['0 0 * * 6#5', '2026-11-01 00:00:00', '2027-01-30 00:00:00'];
        yield 'macro hourly' => ['@hourly', '2026-11-21 10:15:00', '2026-11-21 11:00:00'];
        yield 'macro weekly' => ['@weekly', '2026-11-21 10:15:00', '2026-11-22 00:00:00'];
        yield 'macro monthly' => ['@monthly', '2026-11-21 10:15:00', '2026-12-01 00:00:00'];
        yield 'macro yearly' => ['@yearly', '2026-11-21 10:15:00', '2027-01-01 00:00:00'];
    }

    #[DataProvider('nextRunProvider')]
    public function testPreviousRunIsInverseOfNextRun(string $expression, string $from, string $expected): void
    {
        $cron = CronExpression::parse($expression);
        $next = $cron->nextRun(self::utc($from));
        $previous = $cron->previousRun($next);

        self::assertLessThanOrEqual(self::utc($from), $previous);
        self::assertEquals($next, $cron->nextRun($previous));
    }

    #[DataProvider('previousRunProvider')]
    public function testPreviousRun(string $expression, string $from, string $expected): void
    {
        $previous = CronExpression::parse($expression)->previousRun(self::utc($from));

        self::assertSame($expected, $previous->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function previousRunProvider(): iterable
    {
        yield 'every minute' => ['* * * * *', '2026-11-21 10:15:30', '2026-11-21 10:15:00'];
        yield 'every minute exact' => ['* * * * *', '2026-11-21 10:15:00', '2026-11-21 10:14:00'];
        yield 'quarter hours' => ['*/15 * * * *', '2026-11-21 10:00:00', '2026-11-21 09:45:00'];
        yield 'daily' => ['30 4 * * *', '2026-11-21 04:00:00', '2026-11-20 04:30:00'];
        yield 'year back' => ['0 0 1 1 *', '2026-11-21 00:00:00', '2026-01-01 00:00:00'];
        yield 'leap day' => ['0 0 29 2 *', '2026-11-21 00:00:00', '2024-02-29 00:00:00'];
        yield 'last day of month' => ['0 0 L * *', '2026-11-21 00:00:00', '2026-10-31 00:00:00'];
        yield 'first monday' => ['0 9 * * 1#1', '2026-11-21 00:00:00', '2026-11-02 09:00:00'];
        yield 'weekdays' => ['0 9 * * MON-FRI', '2026-11-21 12:00:00', '2026-11-20 09:00:00'];
    }

    public function testInclusive(): void
    {
        $cron = CronExpression::parse('0 12 * * *');
        $noon = self::utc('2026-11-21 12:00:00');

        self::assertSame('2026-11-21 12:00:00', $cron->nextRun($noon, true)->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-22 12:00:00', $cron->nextRun($noon)->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-21 12:00:00', $cron->previousRun($noon, true)->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-20 12:00:00', $cron->previousRun($noon)->format('Y-m-d H:i:s'));
    }

    public function testInclusiveWithMicroseconds(): void
    {
        $cron = CronExpression::parse('0 12 * * *');
        $from = self::utc('2026-11-21 12:00:00.250000');

        self::assertSame('2026-11-22 12:00:00', $cron->nextRun($from, true)->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-21 12:00:00', $cron->previousRun($from)->format('Y-m-d H:i:s'));
    }

    public function testNextRunsGenerator(): void
    {
        $runs = CronExpression::parse('0 */6 * * *')->nextRuns(5, self::utc('2026-11-21 05:00:00'));
        $formatted = array_map(static fn(DateTimeImmutable $d): string => $d->format('Y-m-d H:i'), iterator_to_array($runs));

        self::assertSame(
            ['2026-11-21 06:00', '2026-11-21 12:00', '2026-11-21 18:00', '2026-11-22 00:00', '2026-11-22 06:00'],
            $formatted,
        );
    }

    public function testNextRunsInclusiveOnlyAffectsFirst(): void
    {
        $runs = CronExpression::parse('0 * * * *')->nextRuns(3, self::utc('2026-11-21 05:00:00'), true);
        $formatted = array_map(static fn(DateTimeImmutable $d): string => $d->format('H:i'), iterator_to_array($runs));

        self::assertSame(['05:00', '06:00', '07:00'], $formatted);
    }

    public function testPreviousRunsGenerator(): void
    {
        $runs = CronExpression::parse('0 0 * * MON')->previousRuns(3, self::utc('2026-11-21 00:00:00'));
        $formatted = array_map(static fn(DateTimeImmutable $d): string => $d->format('Y-m-d'), iterator_to_array($runs));

        self::assertSame(['2026-11-16', '2026-11-09', '2026-11-02'], $formatted);
    }

    public function testZeroCountYieldsNothing(): void
    {
        self::assertSame([], iterator_to_array(CronExpression::parse('* * * * *')->nextRuns(0)));
    }

    public function testNegativeCountThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array(CronExpression::parse('* * * * *')->nextRuns(-1));
    }

    public function testNegativeCountThrowsForPreviousRuns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array(CronExpression::parse('* * * * *')->previousRuns(-1));
    }

    public function testSeconds(): void
    {
        $cron = CronExpression::parse('*/15 * * * * *', true);
        $runs = iterator_to_array($cron->nextRuns(5, self::utc('2026-11-21 10:00:50')));
        $formatted = array_map(static fn(DateTimeImmutable $d): string => $d->format('H:i:s'), $runs);

        self::assertSame(['10:01:00', '10:01:15', '10:01:30', '10:01:45', '10:02:00'], $formatted);
        self::assertSame('10:00:45', $cron->previousRun(self::utc('2026-11-21 10:00:50'))->format('H:i:s'));
        self::assertTrue($cron->isDue(self::utc('2026-11-21 10:00:45')));
        self::assertFalse($cron->isDue(self::utc('2026-11-21 10:00:46')));
    }

    public function testSecondsRollOverMinuteAndHour(): void
    {
        $cron = CronExpression::parse('30 59 23 * * *', true);

        self::assertSame('2026-11-21 23:59:30', $cron->nextRun(self::utc('2026-11-21 23:59:29'))->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-22 23:59:30', $cron->nextRun(self::utc('2026-11-21 23:59:30'))->format('Y-m-d H:i:s'));
    }

    public function testIsDue(): void
    {
        $cron = CronExpression::parse('30 9 * * MON-FRI');

        self::assertTrue($cron->isDue(self::utc('2026-11-23 09:30:00')));
        self::assertTrue($cron->isDue(self::utc('2026-11-23 09:30:59')), 'seconds are ignored without the seconds field');
        self::assertFalse($cron->isDue(self::utc('2026-11-23 09:31:00')));
        self::assertFalse($cron->isDue(self::utc('2026-11-21 09:30:00')), 'saturday');
        self::assertTrue(CronExpression::parse('* * * * *')->isDue());
    }

    public function testIsDueSpecials(): void
    {
        self::assertTrue(CronExpression::parse('0 0 L * *')->isDue(self::utc('2026-02-28 00:00')));
        self::assertFalse(CronExpression::parse('0 0 L * *')->isDue(self::utc('2028-02-28 00:00')));
        self::assertTrue(CronExpression::parse('0 0 * * 5#3')->isDue(self::utc('2026-11-20 00:00')));
        self::assertFalse(CronExpression::parse('0 0 * * 5#3')->isDue(self::utc('2026-11-13 00:00')));
    }

    public function testIsDueForImpossibleExpressionIsFalse(): void
    {
        self::assertFalse(CronExpression::parse('0 0 30 2 *')->isDue(self::utc('2026-03-02 00:00')));
    }

    public function testNoRunFound(): void
    {
        $this->expectException(NoRunFoundException::class);
        $this->expectExceptionMessage('has no run within 100 years');
        CronExpression::parse('0 0 30 2 *')->nextRun(self::utc('2026-01-01'));
    }

    public function testNoPreviousRunFound(): void
    {
        $this->expectException(NoRunFoundException::class);
        CronExpression::parse('0 0 31 4 *')->previousRun(self::utc('2026-01-01'));
    }

    public function testUsesTimezoneOfGivenMoment(): void
    {
        $tokyo = new DateTimeImmutable('2026-11-21 08:30:00', new DateTimeZone('Asia/Tokyo'));
        $next = CronExpression::parse('0 9 * * *')->nextRun($tokyo);

        self::assertSame('2026-11-21 09:00:00 +09:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testExplicitTimezoneOverridesMoment(): void
    {
        $from = self::utc('2026-11-20 23:30:00');
        $next = CronExpression::parse('0 9 * * *')->nextRun($from, false, new DateTimeZone('Asia/Tokyo'));

        self::assertSame('2026-11-21 09:00:00 +09:00', $next->format('Y-m-d H:i:s P'));
        self::assertSame('2026-11-21 00:00:00', $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function testFixedOffsetTimezone(): void
    {
        $from = new DateTimeImmutable('2026-11-21 10:00:00+03:00');
        $next = CronExpression::parse('0 12 * * *')->nextRun($from);

        self::assertSame('2026-11-21 12:00:00 +03:00', $next->format('Y-m-d H:i:s P'));
    }

    public function testAcceptsMutableDateTimeWithoutModifyingIt(): void
    {
        $from = new DateTime('2026-11-21 10:00:00', new DateTimeZone('UTC'));
        $next = CronExpression::parse('0 * * * *')->nextRun($from);

        self::assertSame('2026-11-21 11:00:00', $next->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-21 10:00:00', $from->format('Y-m-d H:i:s'));
    }

    public function testDefaultsToNow(): void
    {
        $before = new DateTimeImmutable();
        $next = CronExpression::parse('* * * * *')->nextRun();

        self::assertGreaterThan($before, $next);
        self::assertLessThanOrEqual(61, $next->getTimestamp() - $before->getTimestamp());
        self::assertLessThan($before, CronExpression::parse('* * * * *')->previousRun());
    }

    /**
     * Cross-checks nextRun() against brute-force minute enumeration in zones without DST.
     */
    #[DataProvider('bruteForceProvider')]
    public function testMatchesBruteForce(string $expression, string $zone): void
    {
        $tz = new DateTimeZone($zone);
        $cron = CronExpression::parse($expression);
        $cursor = new DateTimeImmutable('2026-02-25 00:00:00', $tz);
        $end = $cursor->modify('+10 days');

        $expected = [];
        for ($t = $cursor; $t < $end; $t = $t->modify('+1 minute')) {
            if (self::bruteMatches($cron, $t)) {
                $expected[] = $t->format('Y-m-d H:i');
            }
        }

        $actual = [];
        $run = $cron->nextRun($cursor, true);
        while ($run < $end) {
            $actual[] = $run->format('Y-m-d H:i');
            $run = $cron->nextRun($run);
        }

        self::assertSame($expected, $actual);
        self::assertNotSame([], $actual);
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function bruteForceProvider(): iterable
    {
        $expressions = [
            '*/7 */5 * * *', '0 9-17/2 * * 1-5', '15,45 0 1,28-31 * *', '0 0 * * SAT,SUN', '0 12 1 * MON',
            '*/30 22-2 * * *', '0 6 L * *', '0 6 LW * *', '0 6 1W * *', '0 6 * * 6L', '0 6 * * 0#1', '0 0 */3 * WED',
        ];
        foreach ($expressions as $expression) {
            foreach (['UTC', 'Asia/Tokyo', 'Asia/Kolkata'] as $zone) {
                yield "$expression @ $zone" => [$expression, $zone];
            }
        }
    }

    private static function bruteMatches(CronExpression $cron, DateTimeImmutable $t): bool
    {
        $day = (int) $t->format('j');
        $last = (int) $t->format('t');
        $weekday = (int) $t->format('w');

        $dom = $cron->dayOfMonth->contains($day);
        foreach ($cron->dayOfMonth->specialParts() as $part) {
            $dom = $dom || match ($part->source) {
                'L' => $day === $last,
                'LW' => $day === self::lastWeekdayOfMonth($t),
                '1W' => $day === self::firstWeekdayOfMonth($t),
                default => false,
            };
        }
        $dow = $cron->dayOfWeek->contains($weekday);
        foreach ($cron->dayOfWeek->specialParts() as $part) {
            $dow = $dow || match ($part->source) {
                '6L' => $weekday === 6 && $day + 7 > $last,
                '0#1' => $weekday === 0 && $day <= 7,
                default => false,
            };
        }
        $days = ($cron->dayOfMonth->isWildcard() || $cron->dayOfWeek->isWildcard()) ? ($dom && $dow) : ($dom || $dow);

        return $days
            && $cron->minute->contains((int) $t->format('i'))
            && $cron->hour->contains((int) $t->format('G'))
            && $cron->month->contains((int) $t->format('n'));
    }

    private static function lastWeekdayOfMonth(DateTimeImmutable $t): int
    {
        $d = $t->modify('last day of this month');
        while ((int) $d->format('N') >= 6) {
            $d = $d->modify('-1 day');
        }

        return (int) $d->format('j');
    }

    private static function firstWeekdayOfMonth(DateTimeImmutable $t): int
    {
        $d = $t->modify('first day of this month');
        while ((int) $d->format('N') >= 6) {
            $d = $d->modify('+1 day');
        }

        return (int) $d->format('j');
    }
}
