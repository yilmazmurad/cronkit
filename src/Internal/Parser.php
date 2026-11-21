<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit\Internal;

use YilmazMurad\Cronkit\Exception\InvalidExpressionException;
use YilmazMurad\Cronkit\Field;
use YilmazMurad\Cronkit\FieldPart;
use YilmazMurad\Cronkit\FieldType;
use YilmazMurad\Cronkit\PartKind;

/**
 * Turns a cron expression string into {@see Field} objects.
 *
 * @internal
 */
final class Parser
{
    /** @var array<string, string> */
    public const array MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    /**
     * @return array{second: Field, minute: Field, hour: Field, dayOfMonth: Field, month: Field, dayOfWeek: Field}
     */
    public static function parse(string $expression, bool $withSeconds): array
    {
        $trimmed = trim($expression);
        if ($trimmed === '') {
            throw new InvalidExpressionException($expression, 'expression is empty', 0);
        }

        if ($trimmed[0] === '@') {
            $macro = strtolower($trimmed);
            if (!isset(self::MACROS[$macro])) {
                $position = (int) strpos($expression, '@');
                throw new InvalidExpressionException(
                    $expression,
                    sprintf('unknown macro "%s" (supported: %s)', $trimmed, implode(', ', array_keys(self::MACROS))),
                    $position,
                );
            }
            $expanded = self::MACROS[$macro];
            if ($withSeconds) {
                $expanded = '0 ' . $expanded;
            }

            return self::parse($expanded, $withSeconds);
        }

        preg_match_all('/\S+/', $expression, $matches, PREG_OFFSET_CAPTURE);
        /** @var list<array{0: string, 1: int}> $tokens */
        $tokens = $matches[0];
        $expected = $withSeconds ? 6 : 5;
        $count = count($tokens);
        if ($count !== $expected) {
            $position = $count > $expected ? $tokens[$expected][1] : strlen(rtrim($expression));
            $hint = !$withSeconds && $count === 6 ? ' (enable the seconds field to use 6 fields)' : '';
            throw new InvalidExpressionException(
                $expression,
                sprintf('expected %d fields, got %d%s', $expected, $count, $hint),
                $position,
            );
        }

        $types = [FieldType::Minute, FieldType::Hour, FieldType::DayOfMonth, FieldType::Month, FieldType::DayOfWeek];
        if ($withSeconds) {
            array_unshift($types, FieldType::Second);
        }

        $fields = [];
        foreach ($types as $i => $type) {
            $fields[$type->name] = self::parseField($expression, $type, $tokens[$i][0], $tokens[$i][1]);
        }

        return [
            'second' => $fields['Second'] ?? new Field(FieldType::Second, '0', [new FieldPart(PartKind::Value, 0, 0, 1, '0')]),
            'minute' => $fields['Minute'],
            'hour' => $fields['Hour'],
            'dayOfMonth' => $fields['DayOfMonth'],
            'month' => $fields['Month'],
            'dayOfWeek' => $fields['DayOfWeek'],
        ];
    }

    private static function parseField(string $expression, FieldType $type, string $token, int $offset): Field
    {
        $parts = [];
        $position = $offset;
        foreach (explode(',', $token) as $item) {
            if ($item === '') {
                throw new InvalidExpressionException($expression, 'empty list item', $position, $type);
            }
            $parts[] = self::parsePart($expression, $type, $item, $position);
            $position += strlen($item) + 1;
        }

        return new Field($type, $token, $parts);
    }

    private static function parsePart(string $expression, FieldType $type, string $item, int $position): FieldPart
    {
        $upper = strtoupper($item);
        $isDay = $type === FieldType::DayOfMonth || $type === FieldType::DayOfWeek;
        $max = $type === FieldType::DayOfWeek ? 6 : $type->max();

        // Steps: "*/5", "1-30/2", "5/15".
        $step = 1;
        $base = $upper;
        $slash = strpos($upper, '/');
        if ($slash !== false) {
            $base = substr($upper, 0, $slash);
            $stepSource = substr($upper, $slash + 1);
            $stepPosition = $position + $slash + 1;
            if ($stepSource === '' || !ctype_digit($stepSource)) {
                throw new InvalidExpressionException($expression, sprintf('invalid step "%s"', $stepSource), $stepPosition, $type);
            }
            $step = (int) $stepSource;
            if ($step < 1) {
                throw new InvalidExpressionException($expression, 'step must be at least 1', $stepPosition, $type);
            }
            if ($step > $type->max() - $type->min() + 1) {
                throw new InvalidExpressionException(
                    $expression,
                    sprintf('step %d is larger than the %s range %d-%d', $step, $type->value, $type->min(), $type->max()),
                    $stepPosition,
                    $type,
                );
            }
            if ($base === '') {
                throw new InvalidExpressionException($expression, 'missing value before "/"', $position, $type);
            }
        }

        if ($base === '*' || $base === '?') {
            if ($base === '?' && !$isDay) {
                throw new InvalidExpressionException($expression, '"?" is only allowed in the day-of-month and day-of-week fields', $position, $type);
            }

            return new FieldPart(PartKind::All, $type->min(), $max, $step, $item);
        }

        if ($step === 1) {
            $special = self::parseSpecial($expression, $type, $base, $item, $position);
            if ($special !== null) {
                return $special;
            }
        } elseif ($isDay && self::parseSpecial($expression, $type, $base, $item, $position) !== null) {
            throw new InvalidExpressionException($expression, 'steps cannot be combined with L, W or #', $position, $type);
        }

        $dash = strpos($base, '-');
        if ($dash !== false) {
            $startSource = substr($base, 0, $dash);
            $endSource = substr($base, $dash + 1);
            $start = self::parseValue($expression, $type, $startSource, $position);
            $end = self::parseValue($expression, $type, $endSource, $position + $dash + 1);
            if ($type === FieldType::DayOfWeek && $start === 7) {
                $start = 0;
            }

            return new FieldPart(PartKind::Range, $start, $end, $step, $item);
        }

        $value = self::parseValue($expression, $type, $base, $position);
        if ($type === FieldType::DayOfWeek && $value === 7) {
            $value = 0;
        }

        return new FieldPart(PartKind::Value, $value, $step > 1 ? $max : $value, $step, $item);
    }

    private static function parseSpecial(string $expression, FieldType $type, string $base, string $item, int $position): ?FieldPart
    {
        if ($type === FieldType::DayOfMonth) {
            if ($base === 'L') {
                return new FieldPart(PartKind::LastDayOfMonth, 0, 0, 1, $item);
            }
            if ($base === 'LW') {
                return new FieldPart(PartKind::LastWeekdayOfMonth, 0, 0, 1, $item);
            }
            if (preg_match('/^L-(\d+)$/', $base, $m) === 1) {
                $offset = (int) $m[1];
                if ($offset > 30) {
                    throw new InvalidExpressionException($expression, sprintf('offset %d in "L-n" must be between 0 and 30', $offset), $position + 2, $type);
                }

                return new FieldPart(PartKind::LastDayOfMonth, $offset, $offset, 1, $item);
            }
            if (preg_match('/^(\d+)W$/', $base, $m) === 1) {
                $day = self::parseValue($expression, $type, $m[1], $position);

                return new FieldPart(PartKind::NearestWeekday, $day, $day, 1, $item);
            }
        } elseif ($type === FieldType::DayOfWeek) {
            if (preg_match('/^([0-9A-Z]+)L$/', $base, $m) === 1) {
                $weekday = self::parseValue($expression, $type, $m[1], $position) % 7;

                return new FieldPart(PartKind::LastWeekdayOccurrence, $weekday, $weekday, 1, $item);
            }
            if (preg_match('/^([0-9A-Z]+)#(\d*)$/', $base, $m) === 1) {
                $weekday = self::parseValue($expression, $type, $m[1], $position) % 7;
                $nth = $m[2] === '' ? 0 : (int) $m[2];
                if ($nth < 1 || $nth > 5) {
                    throw new InvalidExpressionException(
                        $expression,
                        sprintf('occurrence "%s" after "#" must be between 1 and 5', $m[2]),
                        $position + strlen($m[1]) + 1,
                        $type,
                    );
                }

                return new FieldPart(PartKind::NthWeekday, $weekday, $nth, 1, $item);
            }
        }

        return null;
    }

    private static function parseValue(string $expression, FieldType $type, string $source, int $position): int
    {
        if ($source === '') {
            throw new InvalidExpressionException($expression, 'missing value', $position, $type);
        }

        if (ctype_digit($source)) {
            $value = (int) $source;
            if ($value < $type->min() || $value > $type->max()) {
                throw new InvalidExpressionException(
                    $expression,
                    sprintf('value %d is out of range %d-%d', $value, $type->min(), $type->max()),
                    $position,
                    $type,
                );
            }

            return $value;
        }

        $names = $type->names();
        if (isset($names[$source])) {
            return $names[$source];
        }

        $hint = $names !== [] ? sprintf(' (expected a number or one of %s)', implode(', ', array_keys($names))) : ' (expected a number)';
        $hint .= match ($type) {
            FieldType::DayOfMonth => '; special forms: L, L-n, LW, nW',
            FieldType::DayOfWeek => '; special forms: nL, n#k',
            default => '',
        };
        throw new InvalidExpressionException($expression, sprintf('invalid value "%s"%s', $source, $hint), $position, $type);
    }
}
