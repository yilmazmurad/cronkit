# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-21

### Added
- `CronExpression::parse()` / `isValid()` for standard 5-field expressions, an optional leading seconds field and the macros `@yearly`, `@annually`, `@monthly`, `@weekly`, `@daily`, `@midnight`, `@hourly`.
- Lists, ranges (including wrap-around ranges such as `22-2` or `FRI-MON`), steps, month and weekday names, `7` as Sunday and `?`.
- Special day forms: `L`, `L-n`, `LW`, `nW` (day of month) and `nL`, `n#k` (day of week).
- Vixie cron day-of-month / day-of-week OR semantics.
- `isDue()`, `nextRun()`, `previousRun()`, `nextRuns()` and `previousRuns()` generators.
- Explicit, tested daylight saving time handling (spring-forward gaps and fall-back overlaps).
- `InvalidExpressionException` with the zero-based error position, field and a caret marker.
- `describe()` – human-readable English descriptions.
- `Schedule` registry with per-job payloads and time zones, `dueJobs()` and `upcoming()`.

[1.0.0]: https://github.com/yilmazmurad/cronkit/releases/tag/v1.0.0
