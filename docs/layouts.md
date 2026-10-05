# Layouts, blocks and stacks

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

## Passing data to a layout

Layouts receive the configured globals and the page content, but not the page render data. Pass exactly what the layout
needs, either from the template or from the caller:

```php
<?php $v->layout('layouts/app', ['currentUser' => $user, 'breadcrumbs' => $breadcrumbs]) ?>
```

```php
echo $view->render('appointments/index', $data, layout: 'layouts/app', layoutData: ['currentUser' => $user]);
```

The layout then reads them like any other data:

```php
<header><?= $v->e($currentUser->name) ?></header>
<main><?= $v->content() ?></main>
```

Layout data is explicit and per-render. It is the recommended alternative to hiding request-specific values in globals or
in blocks. `content` is reserved: layout data cannot override the page content.

Data passed to `render()` as `sharedData` is available in every layout automatically, so capabilities such as a URL
generator or the current locale do not need to be forwarded to each layout. See
[Templates and runtime](templates.md#passing-data).

A layout can chain to another layout. Each layout may provide its own data, and the same rules apply for every step.

## Blocks and stacks

Blocks and stacks use balanced captures:

```php
<?php $v->start('sidebar') ?>
<nav>...</nav>
<?php $v->end() ?>

<?php $v->push('head') ?>
<link rel="stylesheet" href="/appointments.css">
<?php $v->end() ?>
```

- `start($name)` / `end()` define a named block rendered later with `$v->block($name)`.
- `push($name)` / `end()` append to a stack rendered with `$v->stack($name)`.

A capture must be closed by the same template scope that opened it. Failed templates restore the render context and output
buffer stack, so a caught partial exception cannot leave the next render in a corrupted state. Circular layout chains are
also rejected.
