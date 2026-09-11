# Includes and loops

`inc()` renders a partial with an isolated scope:

```php
<?= $v->inc('appointments/card', ['appointment' => $appointment]) ?>
```

The partial receives its explicit data, configured globals and `$v`. It does not see the parent template's local
variables. A partial cannot select a layout.

## Loops

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

The short form also accepts `empty` (template rendered when the iterable is empty) and `data` (extra data shared by every
item template):

```php
<?= $v->each($cards, 'partials/card', as: 'card', empty: 'partials/empty', data: ['compact' => true]) ?>
```

## Loop metadata

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

## Recursion

For recursive views, use `EachOptions` to carry the depth explicitly:

```php
<?= $v->each(
    $children,
    'menu/item',
    \Kaly\Tpl\EachOptions::as('item')->depth0($loop->depth()),
) ?>
```

The default maximum depth is 50.
