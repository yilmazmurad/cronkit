**English** | [Türkçe](README.tr.md)

# cronkit

[![CI](https://github.com/yilmazmurad/cronkit/actions/workflows/ci.yml/badge.svg)](https://github.com/yilmazmurad/cronkit/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.4-777bb4)
![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A zero-dependency cron expression library for PHP 8.3+. Parse cron expressions, check whether they are due,
compute next/previous run times with **correct time zone and DST handling**, and turn them into plain English.

```php
use YilmazMurad\Cronkit\CronExpression;

$cron = CronExpression::parse('30 9 * * MON-FRI');

$cron->describe();                       // "At 09:30, Monday through Friday"
$cron->isDue(new DateTimeImmutable());   // true / false
$cron->nextRun(new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')));
```

## Features

- **Standard syntax** – 5 fields (`minute hour day-of-month month day-of-week`), optional leading **seconds** field (off by default).
- Lists (`1,15,30`), ranges (`9-17`), steps (`*/5`, `0-30/10`, `5/15`), wrap-around ranges (`22-2`, `FRI-MON`).
- Month and weekday names (`JAN`–`DEC`, `SUN`–`SAT`, case-insensitive), `7` = Sunday, `?` = `*` in the day fields.
- Macros: `@yearly`, `@annually`, `@monthly`, `@weekly`, `@daily`, `@midnight`, `@hourly`.
- Special day forms: `L`, `L-n`, `LW`, `nW` (day of month), `nL`, `n#k` (day of week).
- **Vixie cron semantics**: when both day-of-month and day-of-week are restricted, a day matches if *either* matches.
- `isDue()`, `nextRun()`, `previousRun()` and lazy `nextRuns()` / `previousRuns()` generators.
- **Explicit DST rules** (spring-forward gaps and fall-back overlaps), backed by tests for `America/New_York`, `Europe/Berlin` and `Australia/Lord_Howe`.
- Clear `InvalidExpressionException`s with the **position** and field of the problem.
- `describe()` – human-readable English description.
- Small `Schedule` registry that returns due jobs.
- Immutable (`readonly`) value objects, enums, `strict_types`, PHPStan level max, no runtime dependencies.

## Installation

```bash
composer require yilmazmurad/cronkit
```

Requires PHP 8.3 or newer.

## Usage

### Parsing and validation

```php
use YilmazMurad\Cronkit\CronExpression;
use YilmazMurad\Cronkit\Exception\InvalidExpressionException;

CronExpression::isValid('*/5 * * * *');   // true
CronExpression::isValid('61 * * * *');    // false

$cron = CronExpression::parse('@weekly');
echo $cron;                               // "0 0 * * 0" (normalised)

try {
    CronExpression::parse('0 25 * * *');
} catch (InvalidExpressionException $e) {
    $e->getPosition();   // 2
    $e->getFieldType();  // FieldType::Hour
    $e->getReason();     // "value 25 is out of range 0-23"
    echo $e->getMessage();
    // Invalid cron expression "0 25 * * *" at position 2 (hour field): value 25 is out of range 0-23
    //   0 25 * * *
    //     ^
}
```

### Next and previous runs

```php
$cron = CronExpression::parse('0 9 * * MON-FRI');
$from = new DateTimeImmutable('2026-11-21 12:00', new DateTimeZone('Europe/Berlin'));

$cron->nextRun($from);                 // 2026-11-23 09:00 (Europe/Berlin)
$cron->previousRun($from);             // 2026-11-20 09:00
$cron->nextRun($from, inclusive: true);

foreach ($cron->nextRuns(5, $from) as $run) {
    echo $run->format(DATE_ATOM), PHP_EOL;
}

// Evaluate in another zone than the one of $from:
$cron->nextRun($from, timezone: new DateTimeZone('America/New_York'));
```

All methods accept any `DateTimeInterface` and return `DateTimeImmutable` in the evaluation zone
(the zone of the given moment unless `$timezone` is passed). Without arguments, "now" in PHP's default zone is used.
If an expression can never run (e.g. `0 0 30 2 *`), `NoRunFoundException` is thrown after searching 100 years.

### Seconds

```php
$cron = CronExpression::parse('*/10 * * * * *', withSeconds: true);
$cron->describe(); // "Every 10 seconds"
```

### Is it due?

```php
CronExpression::parse('*/15 * * * *')->isDue(); // now, minute precision
CronExpression::parse('0 0 L * *')->isDue(new DateTimeImmutable('2026-02-28 00:00'));
```

### Human-readable descriptions

| Expression | `describe()` |
| --- | --- |
| `* * * * *` | Every minute |
| `0 */2 * * *` | Every 2 hours |
| `30 9 * * MON-FRI` | At 09:30, Monday through Friday |
| `*/15 9-17 * * 1-5` | Every 15 minutes, between 09:00 and 17:59, Monday through Friday |
| `0 0 1,15 * 1` | At 00:00, on days 1 and 15 of the month, or on Monday |
| `0 12 * * 5L` | At 12:00, on the last Friday of the month |
| `0 0 15W * *` | At 00:00, on the weekday nearest day 15 of the month |
| `@yearly` | At 00:00, on day 1 of the month, in January |

### Schedule registry

```php
use YilmazMurad\Cronkit\Schedule;

$schedule = (new Schedule())
    ->add('backup', '0 3 * * *', fn () => runBackup())
    ->add('report', '0 9 * * MON', 'app:weekly-report')
    ->add('tokyo', '0 9 * * *', 'app:tokyo', new DateTimeZone('Asia/Tokyo'));

foreach ($schedule->dueJobs(new DateTimeImmutable()) as $job) {
    echo "Running {$job->name}\n";
    // $job->payload, $job->expression, $job->timezone
}

$schedule->upcoming(); // ['backup' => DateTimeImmutable, ...] sorted by time
```

## Semantics

### Day-of-month vs. day-of-week (Vixie cron)

If **either** day field starts with `*` (or `?`), both must match (`AND`). Otherwise a day matches when
**either** matches (`OR`):

- `0 0 15 * MON` – the 15th of every month **and** every Monday.
- `0 0 */2 * MON` – every Monday that is an odd day of the month (`*/2` starts with `*`).

### Daylight saving time

Expressions are evaluated in local wall-clock time:

- **Spring forward (gap)** – local times that do not exist are skipped. If the hour field is restricted
  (e.g. `30 2 * * *`) and a matching time falls into the gap, the job runs **once at the transition**
  (03:00 local), so a daily job is never lost. Jobs whose hour field matches every hour (e.g. `*/15 * * * *`)
  simply continue after the gap.
- **Fall back (overlap)** – repeated local times run **once** (first occurrence) for jobs with a restricted
  hour field, e.g. `30 1 * * *`. Jobs whose hour field matches every hour run in **both** occurrences,
  keeping `@hourly` exactly one hour apart.

`isDue()`, `nextRun()` and `previousRun()` all follow the same rules, so they are always consistent with each other.

### Special characters

| Field | Form | Meaning |
| --- | --- | --- |
| day of month | `L` | last day of the month |
| day of month | `L-3` | 3 days before the last day |
| day of month | `LW` | last weekday (Mon–Fri) of the month |
| day of month | `15W` | weekday nearest the 15th (never crosses the month) |
| day of week | `5L` / `FRIL` | last Friday of the month |
| day of week | `1#2` / `MON#2` | second Monday of the month |

## API

| Method | Description |
| --- | --- |
| `CronExpression::parse(string $expr, bool $withSeconds = false): CronExpression` | Parse or throw `InvalidExpressionException`. |
| `CronExpression::isValid(string $expr, bool $withSeconds = false): bool` | Validate without throwing. |
| `isDue(?DateTimeInterface $at = null, ?DateTimeZone $tz = null): bool` | Whether the expression runs at that moment. |
| `nextRun(?DateTimeInterface $from = null, bool $inclusive = false, ?DateTimeZone $tz = null): DateTimeImmutable` | Next run after `$from`. |
| `previousRun(...same...): DateTimeImmutable` | Last run before `$from`. |
| `nextRuns(int $count, ...): Generator<DateTimeImmutable>` | Lazily yields the next `$count` runs. |
| `previousRuns(int $count, ...): Generator<DateTimeImmutable>` | Lazily yields previous runs, newest first. |
| `describe(): string` | English description. |
| `toString()` / `__toString()` | Normalised expression. |
| `getField(FieldType $type): Field` | Parsed field (`values`, `parts`, `source`). |
| `Schedule::add()/remove()/has()/get()/dueJobs()/upcoming()` | Job registry (`Countable`, `IteratorAggregate`). |

Exceptions implement `YilmazMurad\Cronkit\Exception\CronkitException`:
`InvalidExpressionException` (extends `InvalidArgumentException`) and `NoRunFoundException` (extends `RuntimeException`).

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan (level max)
composer cs        # php-cs-fixer (dry run), `composer cs:fix` to fix
composer check     # all of the above
```

CI runs the full suite on PHP 8.3 and 8.4.

## License

[MIT](LICENSE) © 2026 Murat Yılmaz
