# Includes and loops

`inc()` renders a partial with an isolated scope:

```php
<?= $v->inc('appointments/card', ['appointment' => $appointment]) ?>
```

The partial receives its explicit data, configured globals, the render `sharedData` and `$v`. It does not see the parent
template's local variables. A partial cannot select a layout.

## Inline loops

Kaly Tpl is PHP. When the repeated markup belongs to the current template, use a native `foreach`:

```php
<ul>
<?php foreach ($items as $item): ?>
    <li><?= $v->e($item['label']) ?></li>
<?php endforeach ?>
</ul>
```

Use `each()` when each item is naturally its own template, or when its loop metadata, recursion, empty-template handling,
or generator lookahead is useful. A `<li>` is not a component just because it is inside a loop.

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
```

`last()` works for generators by reading one item ahead. `length()` is available only for countable iterables.

## Recursion

When an item template renders its own children, continue the loop with `$loop->nested()`. It continues the current
recursive loop one level deeper and preserves its maximum depth:

```php
<?php // menu/item.phtml ?>
<?php $children = $item['children'] ?? [] ?>

<?php if ($children !== []): ?>
    <ul class="submenu">
        <?= $v->each($children, 'menu/item', $loop->nested(as: 'item')) ?>
    </ul>
<?php endif ?>
```

Depth is 1-based. The root level has `depth() === 1`, and the default maximum depth is 50. To use a different limit,
configure the root call once; `nested()` propagates it automatically:

```php
<?php use Kaly\Tpl\EachOptions ?>

<?= $v->each(
    $tree,
    'menu/item',
    EachOptions::as('item')->maxDepth(10),
) ?>
```


