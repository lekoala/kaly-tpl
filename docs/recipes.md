# Recipes

## Typed application helpers

There is no global helper registry and no need to extend `$v`. Inject a small, typed service object and call it from
templates:

```php
final class Ui
{
    public function __construct(private AvatarGenerator $avatars) {}

    public function avatar(User $user, int $size = 32): Html
    {
        return new Html(sprintf('<img src="%s" alt="">', $this->avatars->url($user, $size)));
    }
}

$view->addGlobal('ui', new Ui($avatars));
```

```php
<?= $ui->avatar($user) ?>
```

Because `$ui` is a real object, the IDE completes `avatar()` and its argument types. This is preferable to growing `$v`
or introducing a function registry: it keeps `$v` as the small, stable rendering contract and lets the application own
its helpers. Globals are shared by every template, so use them for services, not request data.

## Components with captures

`start()` / `end()` already cover most "slot" needs without a dedicated component API. Define a component template that
reads a captured block and renders its shell:

```php
<?php // components/modal.phtml ?>
<div class="modal" role="dialog">
    <h2><?= $v->e($title) ?></h2>
    <div class="modal-body"><?= $v->block('body') ?></div>
</div>
```

```php
<?php // in a page ?>
<?php $v->start('body') ?>
    <p><?= $v->e($message) ?></p>
<?php $v->end() ?>

<?= $v->inc('components/modal', ['title' => 'Confirm']) ?>
```

The shell reuses the captured block, while the page keeps full control of the body markup. This stays inside the existing
primitives (`start()`, `block()`, `inc()`) and avoids a second, overlapping component abstraction. Add a dedicated slots
API only if a concrete case cannot be expressed this way.

## Engine lifetime and globals

The engine is mutable: `addGlobal()`, `addPath()`, `locale()`, `escaper()` and `formatter()` change instance state. In a
long-lived worker (RoadRunner, Swoole, FrankenPHP, …) a shared engine would therefore leak per-request values between
requests.

Keep globals to services and application constants, and pass request data to `render()`:

```php
$view->addGlobal('appName', 'My App');     // stable
$view->addGlobal('router', $router);       // service

$view->render(
    'dashboard',
    ['account' => $account],               // page-local
    sharedData: ['url' => $url],           // whole render
);
```

Render data is scoped to the rendered template only: it is not inherited by its includes or layouts. Use the
`sharedData` argument for request capabilities that every template of a render should see (URL generators, the current
locale, CSRF, ...). Shared data is available in the page, its includes, its `each()` templates and its layouts, and is
never stored on the engine. See [Templates and runtime](templates.md#passing-data). This avoids carrying a user or
session through mutable engine state.
