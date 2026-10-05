# Templates and runtime

Templates are plain `.phtml` files. Every template receives a reserved `$v` variable implementing
`Kaly\Tpl\HtmlView`:

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

Includes are isolated: a partial receives only its explicit data, configured globals and `$v`. It does not inherit
arbitrary local variables from the parent template.

## Engine configuration

```php
use Kaly\Tpl\ViewEngine;

$view = (new ViewEngine(__DIR__ . '/views'))
    ->locale('fr_BE', currency: 'EUR', timezone: 'Europe/Brussels')
    ->addGlobal('appName', 'My App');

echo $view->render('appointments/index', ['appointments' => $appointments], layout: 'layouts/app');
```

A different extension can be configured when constructing the engine:

```php
$view = new ViewEngine(__DIR__ . '/views', extension: 'php');
```

Globals are meant for stable application services and constants, not per-request data. See
[Recipes](recipes.md#engine-lifetime-and-globals) for the reasoning.

## Passing data

`render(string $template, array $data = [], ?string $layout = null, array $layoutData = [], array $sharedData = [])`

- `$data` is passed to the rendered template only.
- `$layoutData` is passed to the caller-provided `$layout` (see [Layouts](layouts.md#passing-data-to-a-layout)).
- `$sharedData` is available to the whole render: the page, its includes, its `each()` templates and its layouts.

The available scopes are:

| API | Scope |
|---|---|
| `addGlobal()` | every render, every template |
| `render(..., $data)` | the root template only |
| `sharedData:` | the whole render (page, includes, `each()`, layouts) |
| `inc(..., $data)` | the included partial only |
| `layout(..., $data)` | that layout only |

Use named arguments for `layout`, `layoutData` and `sharedData`:

```php
$view->render('account/index', ['account' => $account], sharedData: ['url' => $url]);
```

Locals override globals: a global is an engine-wide default that a template may specialize. Shared data cannot be
shadowed: a name present in `sharedData` cannot also be a global, nor be redefined by any template of the same render
(root `$data`, `inc()` or `layout()` data, the layout `content`, or the `each()` item, `key` and `loop` variables).
Such a collision throws an `\InvalidArgumentException` naming the conflicting variables and the template chain:

```text
Template data conflicts with shared render data: "url" (template chain: users/index > users/card).
```

Shared data is validated like any other data: names must match `[A-Za-z_][A-Za-z0-9_]*` and cannot be reserved.
