[English](README.md) | **Türkçe**

# cronkit

[![CI](https://github.com/yilmazmurad/cronkit/actions/workflows/ci.yml/badge.svg)](https://github.com/yilmazmurad/cronkit/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.4-777bb4)
![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)
[![Lisans: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

PHP 8.3+ için bağımlılıksız bir cron ifadesi kütüphanesi. Cron ifadelerini ayrıştırın, zamanı gelip gelmediğini
kontrol edin, **saat dilimi ve yaz saati (DST) geçişlerini doğru işleyerek** sonraki/önceki çalışma zamanlarını
hesaplayın ve ifadeleri okunabilir İngilizce metne dönüştürün.

```php
use YilmazMurad\Cronkit\CronExpression;

$cron = CronExpression::parse('30 9 * * MON-FRI');

$cron->describe();                       // "At 09:30, Monday through Friday"
$cron->isDue(new DateTimeImmutable());   // true / false
$cron->nextRun(new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')));
```

## Özellikler

- **Standart sözdizimi** – 5 alan (`dakika saat ayın-günü ay haftanın-günü`), isteğe bağlı baştaki **saniye** alanı (varsayılan kapalı).
- Listeler (`1,15,30`), aralıklar (`9-17`), adımlar (`*/5`, `0-30/10`, `5/15`), başa saran aralıklar (`22-2`, `FRI-MON`).
- Ay ve gün adları (`JAN`–`DEC`, `SUN`–`SAT`, büyük/küçük harf duyarsız), `7` = Pazar, gün alanlarında `?` = `*`.
- Makrolar: `@yearly`, `@annually`, `@monthly`, `@weekly`, `@daily`, `@midnight`, `@hourly`.
- Özel gün biçimleri: `L`, `L-n`, `LW`, `nW` (ayın günü), `nL`, `n#k` (haftanın günü).
- **Vixie cron semantiği**: ayın günü ve haftanın günü alanlarının ikisi de kısıtlıysa, *herhangi biri* eşleşen gün çalışır.
- `isDue()`, `nextRun()`, `previousRun()` ve tembel (lazy) `nextRuns()` / `previousRuns()` üreteçleri.
- **Açıkça tanımlanmış DST kuralları** (ileri alma boşlukları ve geri alma çakışmaları); `America/New_York`, `Europe/Berlin` ve `Australia/Lord_Howe` için testlerle doğrulanmıştır.
- Hatanın **konumunu** ve alanını içeren anlaşılır `InvalidExpressionException` istisnaları.
- `describe()` – okunabilir İngilizce açıklama.
- Zamanı gelen işleri döndüren küçük bir `Schedule` kayıt sınıfı.
- Değiştirilemez (`readonly`) değer nesneleri, enum'lar, `strict_types`, PHPStan level max, çalışma zamanı bağımlılığı yok.

## Kurulum

```bash
composer require yilmazmurad/cronkit
```

PHP 8.3 veya üzeri gerekir.

## Kullanım

### Ayrıştırma ve doğrulama

```php
use YilmazMurad\Cronkit\CronExpression;
use YilmazMurad\Cronkit\Exception\InvalidExpressionException;

CronExpression::isValid('*/5 * * * *');   // true
CronExpression::isValid('61 * * * *');    // false

$cron = CronExpression::parse('@weekly');
echo $cron;                               // "0 0 * * 0" (normalize edilmiş)

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

### Sonraki ve önceki çalışmalar

```php
$cron = CronExpression::parse('0 9 * * MON-FRI');
$from = new DateTimeImmutable('2026-11-21 12:00', new DateTimeZone('Europe/Berlin'));

$cron->nextRun($from);                 // 2026-11-23 09:00 (Europe/Berlin)
$cron->previousRun($from);             // 2026-11-20 09:00
$cron->nextRun($from, inclusive: true);

foreach ($cron->nextRuns(5, $from) as $run) {
    echo $run->format(DATE_ATOM), PHP_EOL;
}

// $from'un dilimi yerine başka bir saat diliminde değerlendirme:
$cron->nextRun($from, timezone: new DateTimeZone('America/New_York'));
```

Tüm metotlar herhangi bir `DateTimeInterface` kabul eder ve değerlendirme diliminde `DateTimeImmutable` döndürür
(`$timezone` verilmediyse verilen anın dilimi). Argüman verilmezse PHP'nin varsayılan dilimindeki "şimdi" kullanılır.
Hiç çalışamayacak bir ifade için (ör. `0 0 30 2 *`) 100 yıllık aramadan sonra `NoRunFoundException` fırlatılır.

### Saniyeler

```php
$cron = CronExpression::parse('*/10 * * * * *', withSeconds: true);
$cron->describe(); // "Every 10 seconds"
```

### Zamanı geldi mi?

```php
CronExpression::parse('*/15 * * * *')->isDue(); // şimdi, dakika hassasiyetinde
CronExpression::parse('0 0 L * *')->isDue(new DateTimeImmutable('2026-02-28 00:00'));
```

### Okunabilir açıklamalar

| İfade | `describe()` |
| --- | --- |
| `* * * * *` | Every minute |
| `0 */2 * * *` | Every 2 hours |
| `30 9 * * MON-FRI` | At 09:30, Monday through Friday |
| `*/15 9-17 * * 1-5` | Every 15 minutes, between 09:00 and 17:59, Monday through Friday |
| `0 0 1,15 * 1` | At 00:00, on days 1 and 15 of the month, or on Monday |
| `0 12 * * 5L` | At 12:00, on the last Friday of the month |
| `0 0 15W * *` | At 00:00, on the weekday nearest day 15 of the month |
| `@yearly` | At 00:00, on day 1 of the month, in January |

### Schedule (iş kaydı)

```php
use YilmazMurad\Cronkit\Schedule;

$schedule = (new Schedule())
    ->add('backup', '0 3 * * *', fn () => runBackup())
    ->add('report', '0 9 * * MON', 'app:weekly-report')
    ->add('tokyo', '0 9 * * *', 'app:tokyo', new DateTimeZone('Asia/Tokyo'));

foreach ($schedule->dueJobs(new DateTimeImmutable()) as $job) {
    echo "Çalışıyor: {$job->name}\n";
    // $job->payload, $job->expression, $job->timezone
}

$schedule->upcoming(); // ['backup' => DateTimeImmutable, ...] zamana göre sıralı
```

## Semantik

### Ayın günü ve haftanın günü (Vixie cron)

Gün alanlarından **herhangi biri** `*` (veya `?`) ile başlıyorsa ikisi de eşleşmelidir (`VE`). Aksi halde
**herhangi biri** eşleştiğinde gün eşleşir (`VEYA`):

- `0 0 15 * MON` – her ayın 15'i **ve** her Pazartesi.
- `0 0 */2 * MON` – ayın tek günlerine denk gelen Pazartesiler (`*/2` `*` ile başlar).

### Yaz saati (DST)

İfadeler yerel duvar saatine göre değerlendirilir:

- **İleri alma (boşluk)** – var olmayan yerel saatler atlanır. Saat alanı kısıtlıysa (ör. `30 2 * * *`) ve
  eşleşen bir saat boşluğa düşüyorsa iş **geçiş anında bir kez** (yerel 03:00) çalışır; böylece günlük bir iş
  asla kaybolmaz. Saat alanı her saati kapsayan işler (ör. `*/15 * * * *`) boşluktan sonra normal şekilde devam eder.
- **Geri alma (çakışma)** – tekrarlanan yerel saatler, saat alanı kısıtlı işler için (ör. `30 1 * * *`)
  **bir kez** (ilk geçişte) çalışır. Saat alanı her saati kapsayan işler **her iki** geçişte de çalışır;
  böylece `@hourly` tam bir saat arayla kalır.

`isDue()`, `nextRun()` ve `previousRun()` aynı kuralları izler, dolayısıyla birbirleriyle her zaman tutarlıdır.

### Özel karakterler

| Alan | Biçim | Anlamı |
| --- | --- | --- |
| ayın günü | `L` | ayın son günü |
| ayın günü | `L-3` | son günden 3 gün önce |
| ayın günü | `LW` | ayın son hafta içi günü (Pzt–Cum) |
| ayın günü | `15W` | 15'ine en yakın hafta içi gün (ay dışına taşmaz) |
| haftanın günü | `5L` / `FRIL` | ayın son Cuması |
| haftanın günü | `1#2` / `MON#2` | ayın ikinci Pazartesisi |

## API

| Metot | Açıklama |
| --- | --- |
| `CronExpression::parse(string $expr, bool $withSeconds = false): CronExpression` | Ayrıştırır, hata varsa `InvalidExpressionException` fırlatır. |
| `CronExpression::isValid(string $expr, bool $withSeconds = false): bool` | İstisna fırlatmadan doğrular. |
| `isDue(?DateTimeInterface $at = null, ?DateTimeZone $tz = null): bool` | İfadenin o anda çalışıp çalışmadığı. |
| `nextRun(?DateTimeInterface $from = null, bool $inclusive = false, ?DateTimeZone $tz = null): DateTimeImmutable` | `$from` sonrasındaki ilk çalışma. |
| `previousRun(...aynı...): DateTimeImmutable` | `$from` öncesindeki son çalışma. |
| `nextRuns(int $count, ...): Generator<DateTimeImmutable>` | Sonraki `$count` çalışmayı tembel olarak üretir. |
| `previousRuns(int $count, ...): Generator<DateTimeImmutable>` | Önceki çalışmaları yeniden eskiye üretir. |
| `describe(): string` | İngilizce açıklama. |
| `toString()` / `__toString()` | Normalize edilmiş ifade. |
| `getField(FieldType $type): Field` | Ayrıştırılmış alan (`values`, `parts`, `source`). |
| `Schedule::add()/remove()/has()/get()/dueJobs()/upcoming()` | İş kaydı (`Countable`, `IteratorAggregate`). |

İstisnalar `YilmazMurad\Cronkit\Exception\CronkitException` arayüzünü uygular:
`InvalidExpressionException` (`InvalidArgumentException`'dan türer) ve `NoRunFoundException` (`RuntimeException`'dan türer).

## Geliştirme

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan (level max)
composer cs        # php-cs-fixer (kuru çalıştırma), düzeltmek için `composer cs:fix`
composer check     # hepsi
```

CI, tüm test paketini PHP 8.3 ve 8.4 üzerinde çalıştırır.

## Lisans

[MIT](LICENSE) © 2026 Murat Yılmaz
