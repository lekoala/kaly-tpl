# Kaly Tpl

[![Latest Version](https://img.shields.io/packagist/v/lekoala/kaly-tpl)](https://packagist.org/packages/lekoala/kaly-tpl) [![Total Downloads](https://img.shields.io/packagist/dt/lekoala/kaly-tpl)](https://packagist.org/packages/lekoala/kaly-tpl) [![License](https://img.shields.io/packagist/l/lekoala/kaly-tpl)](https://packagist.org/packages/lekoala/kaly-tpl) [![PHP Version Require](https://img.shields.io/packagist/php-v/lekoala/kaly-tpl)](https://packagist.org/packages/lekoala/kaly-tpl)

> Lightweight native PHP templates for PHP 8.3+

Kaly Tpl stays close to PHP while adding the small amount of structure usually missing from raw includes:

- native `.phtml` templates with isolated scopes;
- one typed `$v` runtime instead of global helper functions;
- explicit escaping for HTML, attributes, URLs, JSON and attribute bags;
- layouts, blocks and stacks;
- namespaced template paths;
- `each()` with useful loop metadata, including generator lookahead;
- injectable escaping and value formatting;
- native IDE completion without a template-language plugin, with optional development-time PHPDoc generation.

There is no template language, parser, compiler or runtime dependency. Kaly Tpl is intended for **trusted application
views**. It is not a sandbox and must not be used to execute user-authored templates.

## Installation

```bash
composer require lekoala/kaly-tpl
```

## Quick start

```php
use Kaly\Tpl\ViewEngine;

$view = (new ViewEngine(__DIR__ . '/views'))
    ->locale('fr_BE', currency: 'EUR', timezone: 'Europe/Brussels')
    ->addGlobal('appName', 'My App');

echo $view->render(
    'appointments/index',
    ['appointments' => $appointments],
    layout: 'layouts/app',
);
```

Templates use `.phtml` by default:

```text
views/
  layouts/app.phtml
  appointments/index.phtml
  appointments/card.phtml
```

A different extension can be configured when constructing the engine:

```php
$view = new ViewEngine(__DIR__ . '/views', extension: 'php');
```

## Template runtime

Every template receives a reserved `$v` variable implementing `Kaly\Tpl\HtmlView`:

```php
<h1><?= $v->e($title) ?></h1>

<article<?= $v->attrs(['class' => ['card', 'active' => $active]]) ?>>
    <?= $v->datetime($appointment->startsAt) ?>
    <?= $v->money($appointment->amount) ?>
</article>

<?= $v->inc('partials/card', ['card' => $card]) ?>
<?= $v->each($cards, 'partials/card', as: 'card', empty: 'partials/empty') ?>
```

`$v` and the internal `__kaly*` prefix are reserved and cannot be supplied through render data or globals.

Includes are isolated: a partial receives only its explicit data, configured globals and `$v`. It does not inherit arbitrary
local variables from the parent template.

## Escaping

Escaping is explicit. There is no PHP parser attempting to infer the output context.

```php
<?= $v->e($text) ?>
<?= $v->attr($attributeValue) ?>
<a href="<?= $v->url($url) ?>">Profile</a>
<?= $v->json($payload) ?>
```

The default URL helper accepts relative URLs and the `http`, `https`, `mailto` and `tel` schemes. Unsafe schemes,
protocol-relative URLs and URLs containing ASCII control characters are replaced with `#`.

Attribute bags handle escaping and HTML boolean attributes in one place:

```php
<button<?= $v->attrs([
    'class' => ['btn', 'active' => $active],
    'disabled' => $disabled,
    'aria-expanded' => $expanded,
    'data-id' => $id,
]) ?>>
    Save
</button>
```

HTML boolean attributes such as `disabled`, `required` and `checked` are emitted by presence. Other boolean values,
including ARIA and data attributes, are rendered as `"true"` or `"false"`. Common URL attributes such as `href`, `src`
and `action` are passed through the URL policy automatically. Inline event-handler attributes and `srcdoc` are rejected by
`attrs()` because they require a different output context.

`classes()` and `styleVars()` are safe to echo directly inside their corresponding attributes:

```php
<div class="<?= $v->classes('card', ['selected' => $selected]) ?>"
     style="<?= $v->styleVars(['--progress' => $progress]) ?>">
```

`styleVars()` is deliberately narrow: it only builds CSS custom-property declarations and rejects declaration separators.
CSS values themselves should still come from application-controlled conventions rather than arbitrary user CSS.

For deliberately trusted markup, use an explicit `Html` value:

```php
<?= $v->html('<strong>Trusted application markup</strong>') ?>
```

## Formatting

Common display formatting is delegated to an injectable `ValueFormatter`:

```php
<?= $v->date($date) ?>
<?= $v->time($date) ?>
<?= $v->datetime($date) ?>
<?= $v->money($amount, 'EUR') ?>
<?= $v->number($value, decimals: 2) ?>
<?= $v->percent(0.42) ?>
```

`DefaultValueFormatter` uses `IntlDateFormatter` and `NumberFormatter` when `ext-intl` is available. Without `ext-intl`, it
uses deterministic neutral fallbacks (`Y-m-d`, `H:i`, `1,234.50 EUR`, and similar) instead of guessing locale rules.
Invalid numeric strings and non-finite values fail loudly instead of being silently coerced to zero.

Custom strategies can be injected without changing the template API:

```php
$view->escaper(new App\View\AppEscaper());
$view->formatter(new App\View\AppValueFormatter());
```

## Includes and loops

`inc()` renders a partial with an isolated scope:

```php
<?= $v->inc('appointments/card', ['appointment' => $appointment]) ?>
```

`each()` is the loop equivalent. The item template receives the configured item variable, `key`, and a `Loop` object:

```php
<?= $v->each($appointments, 'appointments/card', as: 'appointment') ?>
```

Inside `appointments/card.phtml`:

```php
<?php if ($loop->changed('status', $appointment['status'])): ?>
    <h2><?= $v->e($appointment['status']) ?></h2>
<?php endif ?>

<article class="<?= $v->classes($loop->cycle('odd', 'even')) ?>">
    <h3><?= $v->e($appointment['patient']) ?></h3>
    <p><?= $v->datetime($appointment['startsAt']) ?></p>
</article>
```

Loop metadata includes:

```php
$loop->index0();
$loop->index();
$loop->key();
$loop->first();
$loop->last();
$loop->hasLength();
$loop->length();
$loop->hasPrevious();
$loop->previous();
$loop->hasNext();
$loop->next();
$loop->odd();
$loop->even();
$loop->cycle('odd', 'even');
$loop->changed('group', $value);
$loop->depth();
$loop->depth0();
```

`last()` works for generators by reading one item ahead. `length()` is available only for countable iterables.

For recursive views, use `EachOptions` to carry the depth explicitly:

```php
<?= $v->each(
    $children,
    'menu/item',
    \Kaly\Tpl\EachOptions::as('item')->depth0($loop->depth()),
) ?>
```

The default maximum depth is 50.

## Layouts, blocks and stacks

A page can select a layout itself:

```php
<?php $v->layout('layouts/app') ?>
<?php $v->title('Appointments') ?>

<h1>Appointments</h1>
```

or the caller can provide the first layout:

```php
echo $view->render('appointments/index', $data, layout: 'layouts/app');
```

Layouts access the current content with:

```php
<?= $v->content() ?>
```

Blocks and stacks use balanced captures:

```php
<?php $v->start('sidebar') ?>
<nav>...</nav>
<?php $v->end() ?>

<?php $v->push('head') ?>
<link rel="stylesheet" href="/appointments.css">
<?php $v->end() ?>
```

A capture must be closed by the same template scope that opened it. Failed templates restore the render context and output
buffer stack, so a caught partial exception cannot leave the next render in a corrupted state. Circular layout chains are
also rejected.

## Namespaced paths

Additional view roots can be registered explicitly:

```php
$view->addPath('admin', __DIR__ . '/modules/admin/views');
```

Both namespace syntaxes are supported:

```php
echo $view->render('@admin/users/index');
echo $view->render('admin::users/index');
```

Template existence can be checked without rendering:

```php
if ($view->exists('@admin/users/index')) {
    // ...
}
```

Resolved templates are constrained to their registered root, including when symlinks are involved.

## Template PHPDoc DX

In development, Kaly Tpl can create or update a managed PHPDoc header based on observed render data. Because templates are native PHP, this gives IDE completion and type navigation without requiring a template-language plugin:

```php
$view
    ->debug(true)
    ->autoDocblock(true);
```

Managed blocks are marked with `@kaly-template`. Existing manual docblocks without that marker are left untouched.

```php
<?php

/**
 * @kaly-template
 * @var \Kaly\Tpl\HtmlView $v
 * @var \App\Domain\Appointment $appointment
 */
?>
```

For explicit control:

```php
$view->docblocks(\Kaly\Tpl\TemplateDocblocker::ensureOnly());
$view->docblocks(\Kaly\Tpl\TemplateDocblocker::syncManaged());
$view->docblocks(null);
```

This feature writes to template files and is only run when debug mode is enabled.

## Development

The project follows the same quality baseline as the other Kaly packages:

```bash
composer test
```

This runs PHPUnit, PHPStan at max level with bleeding edge enabled, Mago linting and Mago format checks.

Individual commands are also available:

```bash
composer phpunit
composer phpstan
composer mago:lint
composer mago:format
composer fmt
composer demo
```

CI tests PHP 8.3, 8.4 and 8.5. Tagged releases automatically create a GitHub release.
