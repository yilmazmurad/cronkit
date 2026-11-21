<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YilmazMurad\Cronkit\CronExpression;
use YilmazMurad\Cronkit\Exception\InvalidExpressionException;
use YilmazMurad\Cronkit\FieldType;
use YilmazMurad\Cronkit\PartKind;

final class ParserTest extends TestCase
{
    public function testParsesWildcards(): void
    {
        $cron = CronExpression::parse('* * * * *');

        self::assertSame(range(0, 59), $cron->minute->values);
        self::assertSame(range(0, 23), $cron->hour->values);
        self::assertSame(range(1, 31), $cron->dayOfMonth->values);
        self::assertSame(range(1, 12), $cron->month->values);
        self::assertSame(range(0, 6), $cron->dayOfWeek->values);
        self::assertSame([0], $cron->second->values);
        self::assertFalse($cron->withSeconds);
        self::assertTrue($cron->minute->isAll());
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('fieldValueProvider')]
    public function testFieldValues(string $expression, FieldType $type, array $expected): void
    {
        self::assertSame($expected, CronExpression::parse($expression)->getField($type)->values);
    }

    /**
     * @return iterable<string, array{string, FieldType, list<int>}>
     */
    public static function fieldValueProvider(): iterable
    {
        yield 'single value' => ['5 * * * *', FieldType::Minute, [5]];
        yield 'list' => ['1,2,30 * * * *', FieldType::Minute, [1, 2, 30]];
        yield 'unsorted list with duplicates' => ['30,1,1 * * * *', FieldType::Minute, [1, 30]];
        yield 'range' => ['* 9-12 * * *', FieldType::Hour, [9, 10, 11, 12]];
        yield 'step on wildcard' => ['*/15 * * * *', FieldType::Minute, [0, 15, 30, 45]];
        yield 'step on range' => ['* 0-10/3 * * *', FieldType::Hour, [0, 3, 6, 9]];
        yield 'step from value' => ['5/20 * * * *', FieldType::Minute, [5, 25, 45]];
        yield 'mixed list' => ['1,10-12,*/30 * * * *', FieldType::Minute, [0, 1, 10, 11, 12, 30]];
        yield 'wrap-around hours' => ['* 22-2 * * *', FieldType::Hour, [0, 1, 2, 22, 23]];
        yield 'month names' => ['* * * JAN,mar,Dec *', FieldType::Month, [1, 3, 12]];
        yield 'month name range' => ['* * * JUN-AUG *', FieldType::Month, [6, 7, 8]];
        yield 'weekday names' => ['* * * * mon,WED,Fri', FieldType::DayOfWeek, [1, 3, 5]];
        yield 'weekday range' => ['* * * * MON-FRI', FieldType::DayOfWeek, [1, 2, 3, 4, 5]];
        yield 'seven is sunday' => ['* * * * 7', FieldType::DayOfWeek, [0]];
        yield 'range up to seven' => ['* * * * 5-7', FieldType::DayOfWeek, [0, 5, 6]];
        yield 'wrap-around weekdays' => ['* * * * FRI-MON', FieldType::DayOfWeek, [0, 1, 5, 6]];
        yield 'weekday step' => ['* * * * */2', FieldType::DayOfWeek, [0, 2, 4, 6]];
        yield 'question mark' => ['* * ? * *', FieldType::DayOfMonth, range(1, 31)];
        yield 'day of month step' => ['* * 1-31/10 * *', FieldType::DayOfMonth, [1, 11, 21, 31]];
    }

    /**
     * @param array{string, string, string, string, string} $expected
     */
    #[DataProvider('macroProvider')]
    public function testMacros(string $macro, array $expected): void
    {
        $cron = CronExpression::parse($macro);

        self::assertSame(implode(' ', $expected), $cron->toString());
        self::assertSame($macro, $cron->expression);
    }

    /**
     * @return iterable<string, array{string, array{string, string, string, string, string}}>
     */
    public static function macroProvider(): iterable
    {
        yield '@yearly' => ['@yearly', ['0', '0', '1', '1', '*']];
        yield '@annually' => ['@annually', ['0', '0', '1', '1', '*']];
        yield '@monthly' => ['@monthly', ['0', '0', '1', '*', '*']];
        yield '@weekly' => ['@weekly', ['0', '0', '*', '*', '0']];
        yield '@daily' => ['@daily', ['0', '0', '*', '*', '*']];
        yield '@midnight' => ['@midnight', ['0', '0', '*', '*', '*']];
        yield '@hourly' => ['@hourly', ['0', '*', '*', '*', '*']];
        yield 'upper case' => ['@HOURLY', ['0', '*', '*', '*', '*']];
    }

    public function testMacroWithSeconds(): void
    {
        self::assertSame('0 0 * * * *', CronExpression::parse('@hourly', true)->toString());
    }

    public function testSecondsField(): void
    {
        $cron = CronExpression::parse('*/20 5 * * * *', true);

        self::assertTrue($cron->withSeconds);
        self::assertSame([0, 20, 40], $cron->second->values);
        self::assertSame([5], $cron->minute->values);
        self::assertSame('*/20 5 * * * *', (string) $cron);
    }

    public function testToStringCollapsesWhitespace(): void
    {
        self::assertSame('0 12 * * 1', CronExpression::parse("  0\t12   *  * 1 ")->toString());
    }

    public function testSpecialParts(): void
    {
        $cron = CronExpression::parse('0 0 L,LW,L-2,15W * 5L,1#2');

        $domKinds = array_map(static fn($p) => $p->kind, $cron->dayOfMonth->parts);
        self::assertSame(
            [PartKind::LastDayOfMonth, PartKind::LastWeekdayOfMonth, PartKind::LastDayOfMonth, PartKind::NearestWeekday],
            $domKinds,
        );
        self::assertSame(2, $cron->dayOfMonth->parts[2]->start);
        self::assertSame(15, $cron->dayOfMonth->parts[3]->start);
        self::assertSame(PartKind::LastWeekdayOccurrence, $cron->dayOfWeek->parts[0]->kind);
        self::assertSame(5, $cron->dayOfWeek->parts[0]->start);
        self::assertSame(PartKind::NthWeekday, $cron->dayOfWeek->parts[1]->kind);
        self::assertSame([1, 2], [$cron->dayOfWeek->parts[1]->start, $cron->dayOfWeek->parts[1]->end]);
        self::assertSame([], $cron->dayOfWeek->values);
    }

    public function testSpecialWeekdayNames(): void
    {
        $cron = CronExpression::parse('0 0 * * FRIL,SUN#1,7L');

        self::assertSame(5, $cron->dayOfWeek->parts[0]->start);
        self::assertSame(0, $cron->dayOfWeek->parts[1]->start);
        self::assertSame(0, $cron->dayOfWeek->parts[2]->start);
    }

    public function testWildcardDetection(): void
    {
        self::assertTrue(CronExpression::parse('* * */2 * *')->dayOfMonth->isWildcard());
        self::assertFalse(CronExpression::parse('* * */2 * *')->dayOfMonth->isAll());
        self::assertTrue(CronExpression::parse('* * ? * *')->dayOfMonth->isWildcard());
        self::assertFalse(CronExpression::parse('* * 1-31 * *')->dayOfMonth->isWildcard());
        self::assertTrue(CronExpression::parse('* 0-23 * * *')->hour->matchesEveryValue());
    }

    #[DataProvider('validProvider')]
    public function testIsValid(string $expression, bool $withSeconds = false): void
    {
        self::assertTrue(CronExpression::isValid($expression, $withSeconds));
    }

    /**
     * @return iterable<array{0: string, 1?: bool}>
     */
    public static function validProvider(): iterable
    {
        yield ['* * * * *'];
        yield ['0 0 1 1 *'];
        yield ['59 23 31 12 7'];
        yield ['@daily'];
        yield ['0 0 L * ?'];
        yield ['0 9 * * MON-FRI'];
        yield ['* * * * * *', true];
        yield ['0 0 29 2 *'];
        yield ['*/60 * * * *'];
    }

    /**
     * @param array{0: string, 1?: bool} $args
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidExpressions(array $args, int $position, string $reasonFragment, ?FieldType $field): void
    {
        self::assertFalse(CronExpression::isValid(...$args));

        try {
            CronExpression::parse(...$args);
            self::fail('Expected an exception');
        } catch (InvalidExpressionException $e) {
            self::assertSame($position, $e->getPosition(), $e->getMessage());
            self::assertStringContainsString($reasonFragment, $e->getReason());
            self::assertSame($field, $e->getFieldType());
            self::assertSame($args[0], $e->getExpression());
            self::assertStringContainsString('Invalid cron expression', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array{0: string, 1?: bool}, int, string, FieldType|null}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty' => [[''], 0, 'empty', null];
        yield 'blank' => [['   '], 0, 'empty', null];
        yield 'too few fields' => [['* * * *'], 7, 'expected 5 fields, got 4', null];
        yield 'too many fields' => [['* * * * * *'], 10, 'enable the seconds field', null];
        yield 'too few with seconds' => [['* * * * *', true], 9, 'expected 6 fields, got 5', null];
        yield 'minute out of range' => [['60 * * * *'], 0, 'value 60 is out of range 0-59', FieldType::Minute];
        yield 'hour out of range' => [['0 24 * * *'], 2, 'out of range 0-23', FieldType::Hour];
        yield 'day zero' => [['0 0 0 * *'], 4, 'out of range 1-31', FieldType::DayOfMonth];
        yield 'month 13' => [['0 0 1 13 *'], 6, 'out of range 1-12', FieldType::Month];
        yield 'weekday 8' => [['0 0 * * 8'], 8, 'out of range 0-7', FieldType::DayOfWeek];
        yield 'second out of range' => [['60 * * * * *', true], 0, 'out of range 0-59', FieldType::Second];
        yield 'bad name' => [['0 0 * FOO *'], 6, 'invalid value "FOO"', FieldType::Month];
        yield 'weekday name in month' => [['0 0 * MON *'], 6, 'invalid value "MON"', FieldType::Month];
        yield 'letters in minute' => [['abc * * * *'], 0, 'expected a number', FieldType::Minute];
        yield 'negative' => [['-1 * * * *'], 0, 'missing value', FieldType::Minute];
        yield 'range end bad' => [['0 1-25 * * *'], 4, 'out of range 0-23', FieldType::Hour];
        yield 'empty list item' => [['1,,2 * * * *'], 2, 'empty list item', FieldType::Minute];
        yield 'trailing comma' => [['0 1, * * *'], 4, 'empty list item', FieldType::Hour];
        yield 'zero step' => [['*/0 * * * *'], 2, 'step must be at least 1', FieldType::Minute];
        yield 'empty step' => [['*/ * * * *'], 2, 'invalid step', FieldType::Minute];
        yield 'non numeric step' => [['*/x * * * *'], 2, 'invalid step "X"', FieldType::Minute];
        yield 'step too large' => [['0 */25 * * *'], 4, 'step 25 is larger', FieldType::Hour];
        yield 'missing base' => [['/5 * * * *'], 0, 'missing value before "/"', FieldType::Minute];
        yield 'question mark in hour' => [['0 ? * * *'], 2, '"?" is only allowed', FieldType::Hour];
        yield 'L in minute' => [['L * * * *'], 0, 'invalid value "L"', FieldType::Minute];
        yield 'L in weekday alone' => [['0 0 * * L'], 8, 'special forms: nL, n#k', FieldType::DayOfWeek];
        yield 'W out of range' => [['0 0 32W * *'], 4, 'out of range 1-31', FieldType::DayOfMonth];
        yield 'L offset too big' => [['0 0 L-31 * *'], 6, 'between 0 and 30', FieldType::DayOfMonth];
        yield 'hash too big' => [['0 0 * * 1#6'], 10, 'between 1 and 5', FieldType::DayOfWeek];
        yield 'hash missing' => [['0 0 * * 1#'], 10, 'between 1 and 5', FieldType::DayOfWeek];
        yield 'hash in dom' => [['0 0 1#2 * *'], 4, 'special forms: L, L-n, LW, nW', FieldType::DayOfMonth];
        yield 'step with L' => [['0 0 L/2 * *'], 4, 'steps cannot be combined', FieldType::DayOfMonth];
        yield 'unknown macro' => [['@reboot'], 0, 'unknown macro "@reboot"', null];
        yield 'unknown macro with spaces' => [['  @every5m'], 2, 'unknown macro', null];
        yield 'error position in list' => [['0 0 1,5,99 * *'], 8, 'value 99', FieldType::DayOfMonth];
    }

    public function testMessageContainsCaret(): void
    {
        try {
            CronExpression::parse('0 0 * * 9');
            self::fail('Expected an exception');
        } catch (InvalidExpressionException $e) {
            self::assertSame(
                "Invalid cron expression \"0 0 * * 9\" at position 8 (day-of-week field): value 9 is out of range 0-7\n  0 0 * * 9\n          ^",
                $e->getMessage(),
            );
        }
    }
}
