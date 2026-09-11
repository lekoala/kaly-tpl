# Formatting

Common display formatting is delegated to an injectable `ValueFormatter`:

```php
<?= $v->date($date) ?>
<?= $v->time($date) ?>
<?= $v->datetime($date) ?>
<?= $v->money($amount, 'EUR') ?>
<?= $v->number($value, decimals: 2) ?>
<?= $v->percent(0.42) ?>
```

`DefaultValueFormatter` uses `IntlDateFormatter` and `NumberFormatter` when `ext-intl` is available. Without `ext-intl`,
it uses deterministic neutral fallbacks (`Y-m-d`, `H:i`, `1,234.50 EUR`, and similar) instead of guessing locale rules.
Invalid numeric strings and non-finite values fail loudly instead of being silently coerced to zero.

A configured timezone is always applied, including when the input string already carries an explicit offset, so a string
and the equivalent `DateTimeInterface` object format identically.

Locale, currency and timezone can be set on the engine:

```php
$view->locale('fr_BE', currency: 'EUR', timezone: 'Europe/Brussels');
```

## Custom strategies

Escaping and formatting can be replaced without changing the template API:

```php
$view->escaper(new App\View\AppEscaper());
$view->formatter(new App\View\AppValueFormatter());
```

Both must implement `Kaly\Tpl\Escaper` and `Kaly\Tpl\ValueFormatter` respectively.
