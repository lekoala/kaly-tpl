# Kaly Tpl

[![Latest Version](https://img.shields.io/packagist/v/lekoala/kaly-tpl)](https://packagist.org/packages/lekoala/kaly-tpl) [![Total Downloads](https://img.shields.io/packagist/dt/lekoala/kaly-tpl)](https://packagist.org/packages/lekoala/kaly-tpl) [![License](https://img.shields.io/packagist/l/lekoala/kaly-tpl)](https://packagist.org/packages/lekoala/kaly-tpl) [![PHP Version Require](https://img.shields.io/packagist/php-v/lekoala/kaly-tpl)](https://packagist.org/packages/lekoala/kaly-tpl)

> Lightweight native PHP templates for PHP 8.3+

Kaly Tpl stays close to PHP while adding the small amount of structure usually missing from raw includes:

- native `.phtml` templates with isolated scopes;
- one typed `$v` runtime instead of global helper functions;
- explicit escaping for HTML, attributes, URLs, JSON and attribute bags;
- layouts, blocks and stacks, with explicit layout data;
- namespaced template paths;
- `each()` with useful loop metadata, including generator lookahead;
- injectable escaping and value formatting;
- native IDE completion without a template-language plugin, with optional development-time PHPDoc generation;
- template include chains attached to render failures.

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

`$v` and the internal `__kaly*` prefix are reserved and cannot be supplied through render data or globals. Includes are
isolated: a partial receives only its explicit data, configured globals, the render `sharedData` and `$v`, never arbitrary
parent locals. Pass `sharedData:` to `render()` for request capabilities that the whole render (page, includes and
layouts) should see.

## Documentation

- [Templates and runtime](docs/templates.md) — `$v`, isolated scopes, reserved names, engine options.
- [Escaping](docs/escaping.md) — `e`, `attr`, `url`, `json`, `attrs`, and how to compose them safely.
- [Formatting](docs/formatting.md) — dates, numbers, money, and custom escaper/formatter strategies.
- [Includes and loops](docs/includes-and-loops.md) — `inc()`, `each()`, loop metadata and recursion.
- [Layouts, blocks and stacks](docs/layouts.md) — layout selection, passing data to layouts, captures.
- [Namespaced paths](docs/namespaces.md) — multiple view roots and existence checks.
- [Errors](docs/errors.md) — the template include chain exposed by `ViewException`.
- [Template PHPDoc DX](docs/phpdoc-dx.md) — development-time managed docblocks.
- [Recipes](docs/recipes.md) — typed application helpers, component/slot patterns, engine lifetime.

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

## License

MIT.
