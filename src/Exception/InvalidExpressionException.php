<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit\Exception;

use InvalidArgumentException;
use YilmazMurad\Cronkit\FieldType;

/**
 * Thrown when a cron expression cannot be parsed.
 *
 * The message contains the offending expression, a short reason and the zero-based
 * character position, followed by a caret line pointing at the problem, e.g.:
 *
 *     Invalid cron expression "61 * * * *" at position 0 (minute field): value 61 is out of range 0-59
 *       61 * * * *
 *       ^
 */
final class InvalidExpressionException extends InvalidArgumentException implements CronkitException
{
    public function __construct(
        private readonly string $expression,
        private readonly string $reason,
        private readonly int $position,
        private readonly ?FieldType $fieldType = null,
    ) {
        $where = $fieldType !== null ? sprintf(' (%s field)', $fieldType->value) : '';
        parent::__construct(sprintf(
            "Invalid cron expression \"%s\" at position %d%s: %s\n  %s\n  %s^",
            $expression,
            $position,
            $where,
            $reason,
            $expression,
            str_repeat(' ', max(0, $position)),
        ));
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Zero-based character offset of the problem inside the expression.
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    public function getFieldType(): ?FieldType
    {
        return $this->fieldType;
    }
}
