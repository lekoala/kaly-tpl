# Formatting

Common display formatting is delegated to an injectable `ValueFormatter`:

```php
<?= $v->date($date) ?>
<?= $v->time($date) ?>
<?= $v->datetime($date) ?>
<?= $v->money($amount, 'EUR') ?>
<?= $v->number($value, decimals: 2) ?>
<?= $v->percent(0.42) ?>
<?= $v->duration(3723) ?>
<?= $v->duration($interval, 'long') ?>
```

`DefaultValueFormatter` uses `IntlDateFormatter` and `NumberFormatter` when `ext-intl` is available. Without `ext-intl`,
it uses deterministic neutral fallbacks (`Y-m-d`, `H:i`, `1,234.50 EUR`, and similar) instead of guessing locale rules.
Invalid numeric strings and non-finite values fail loudly instead of being silently coerced to zero. Date styles are
`short`, `medium`, `long` and `full`; any other style throws.

The default formatter is meant for display, not for exact monetary representation:

- every number goes through a `float`, so very large integers and decimal strings can lose precision
  (with `ext-intl`, `number(PHP_INT_MAX)` renders `9,223,372,036,854,776,000`);
- without `ext-intl`, `money()` always renders two decimals, including for currencies that use none (`1,234.00 JPY`).

Keep exact amounts in the domain and billing code; templates only present them. Inject a dedicated `ValueFormatter` when
exact rendering matters.

Durations are always deterministic and locale-neutral, regardless of `ext-intl`: `duration()` renders a clock-style
`m:ss` or `h:mm:ss` (hours can exceed 24) by default, and a spelled-out `1 h 2 min 3 s` form with the `long` style. It
accepts a number of seconds or a `DateInterval` that resolves to a fixed duration; intervals carrying years or months
without a `days` total are calendar-relative and throw. For richer temporal types (civil time-of-day, calendar
intervals), inject an application-level formatter as a global instead of widening `time()` to `mixed`.
Seconds are rounded to the nearest integer; the rounded absolute value must be below `(float) PHP_INT_MAX`.
Larger values throw, including `PHP_INT_MAX` itself, which rounds up when converted to a float on 64-bit platforms.

A configured timezone is always applied, including when the input string already carries an explicit offset, so a string
and the equivalent `DateTimeInterface` object format identically.

Locale, currency and timezone can be set on the engine:

```php
$view->locale('fr_BE', currency: 'EUR', timezone: 'Europe/Brussels');
```

The locale is validated right away (`fr_BE`, `fr-BE`, `zh_Hant_TW`, `en_US@currency=EUR`…), since ICU accepts almost
any string and would only fail, or silently fall back, at the first render.
The default currency and each `money()` override must contain exactly three ASCII letters (case-insensitive).
This checks the shape of an ISO 4217 code, not membership in a currency registry.

## Custom strategies

Escaping and formatting can be replaced without changing the template API:

```php
$view->escaper(new App\View\AppEscaper());
$view->formatter(new App\View\AppValueFormatter());
```

Both must implement `Kaly\Tpl\Escaper` and `Kaly\Tpl\ValueFormatter` respectively.
