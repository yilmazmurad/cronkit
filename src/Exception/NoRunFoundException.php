<?php

declare(strict_types=1);

namespace YilmazMurad\Cronkit\Exception;

use RuntimeException;

/**
 * Thrown when an expression has no run inside the search horizon (e.g. `0 0 30 2 *`).
 */
final class NoRunFoundException extends RuntimeException implements CronkitException {}
