<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YilmazMurad\Cronkit\CronExpression;

final class DescribeTest extends TestCase
{
    #[DataProvider('descriptionProvider')]
    public function testDescribe(string $expression, string $expected, bool $withSeconds = false): void
    {
        self::assertSame($expected, CronExpression::parse($expression, $withSeconds)->describe());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: bool}>
     */
    public static function descriptionProvider(): iterable
    {
        yield 'every minute' => ['* * * * *', 'Every minute'];
        yield 'every 5 minutes' => ['*/5 * * * *', 'Every 5 minutes'];
        yield 'every hour' => ['0 * * * *', 'Every hour'];
        yield 'hourly macro' => ['@hourly', 'Every hour'];
        yield 'every 2 hours' => ['0 */2 * * *', 'Every 2 hours'];
        yield 'minute past every hour' => ['30 * * * *', 'At minute 30 of every hour'];
        yield 'minutes past every hour' => ['15,45 * * * *', 'At minutes 15 and 45 of every hour'];
        yield 'minute with hour step' => ['5 */2 * * *', 'At minute 5, every 2 hours'];
        yield 'fixed time' => ['30 9 * * *', 'At 09:30'];
        yield 'daily macro' => ['@daily', 'At 00:00'];
        yield 'two fixed times' => ['0 9,17 * * *', 'At 09:00 and 17:00'];
        yield 'weekdays' => ['30 9 * * MON-FRI', 'At 09:30, Monday through Friday'];
        yield 'weekend list' => ['0 10 * * SAT,SUN', 'At 10:00, on Saturday and Sunday'];
        yield 'weekly macro' => ['@weekly', 'At 00:00, on Sunday'];
        yield 'sunday as 7' => ['0 8 * * 7', 'At 08:00, on Sunday'];
        yield 'monthly macro' => ['@monthly', 'At 00:00, on day 1 of the month'];
        yield 'yearly macro' => ['@yearly', 'At 00:00, on day 1 of the month, in January'];
        yield 'business hours' => ['*/15 9-17 * * MON-FRI', 'Every 15 minutes, between 09:00 and 17:59, Monday through Friday'];
        yield 'single hour window' => ['*/10 9 * * *', 'Every 10 minutes, between 09:00 and 09:59'];
        yield 'hour list window' => ['0-29 9,12 * * *', 'Every minute from 0 through 29, during hours 09 and 12'];
        yield 'hour range step' => ['0 8-18/2 * * *', 'At minute 0, every 2 hours between 08:00 and 18:59'];
        yield 'minute range step' => ['0-30/10 * * * *', 'Every 10 minutes from 0 through 30'];
        yield 'minute value step' => ['5/15 * * * *', 'Every 15 minutes starting at minute 5'];
        yield 'dom list' => ['0 0 1,15 * *', 'At 00:00, on days 1 and 15 of the month'];
        yield 'dom range' => ['0 0 1-7 * *', 'At 00:00, on days 1 through 7 of the month'];
        yield 'dom step' => ['0 0 */2 * *', 'At 00:00, every 2 days'];
        yield 'dom or dow' => ['0 0 1,15 * 1', 'At 00:00, on days 1 and 15 of the month, or on Monday'];
        yield 'dom and dow (star)' => ['0 0 */2 * MON', 'At 00:00, every 2 days, on Monday'];
        yield 'last day' => ['0 0 L * *', 'At 00:00, on the last day of the month'];
        yield 'L minus' => ['0 0 L-3 * *', 'At 00:00, 3 days before the last day of the month'];
        yield 'last weekday' => ['0 18 LW * *', 'At 18:00, on the last weekday of the month'];
        yield 'nearest weekday' => ['0 0 15W * *', 'At 00:00, on the weekday nearest day 15 of the month'];
        yield 'last friday' => ['0 12 * * 5L', 'At 12:00, on the last Friday of the month'];
        yield 'third friday' => ['0 12 * * FRI#3', 'At 12:00, on the third Friday of the month'];
        yield 'single month' => ['0 0 1 JUL *', 'At 00:00, on day 1 of the month, in July'];
        yield 'month list' => ['0 0 1 1,4,7,10 *', 'At 00:00, on day 1 of the month, in January, April, July and October'];
        yield 'month range' => ['0 0 * JUN-AUG *', 'At 00:00, from June through August'];
        yield 'month step' => ['0 0 1 */3 *', 'At 00:00, on day 1 of the month, every 3 months'];
        yield 'weekday step' => ['0 0 * * */2', 'At 00:00, every 2 days of the week'];
        yield 'every second' => ['* * * * * *', 'Every second', true];
        yield 'every 10 seconds' => ['*/10 * * * * *', 'Every 10 seconds', true];
        yield 'fixed time with seconds' => ['15 30 9 * * *', 'At 09:30:15', true];
        yield 'seconds with zero is plain' => ['0 30 9 * * *', 'At 09:30', true];
        yield 'seconds in minute' => ['*/30 5 * * * *', 'Every 30 seconds, at minute 5 of every hour', true];
    }
}
