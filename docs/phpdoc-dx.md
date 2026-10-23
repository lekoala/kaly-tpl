# Template PHPDoc DX

In development, Kaly Tpl can create or update a managed PHPDoc header based on observed render data. Because templates are
native PHP, this gives IDE completion and type navigation without requiring a template-language plugin:

```php
$view
    ->debug(true)
    ->autoDocblock(true);
```

Managed blocks are marked with `@kaly-template`. Existing manual docblocks without that marker are left untouched; the
managed block is added alongside them.

```php
<?php

/**
 * @kaly-template
 * @var \Kaly\Tpl\HtmlView $v
 * @var \App\Domain\Appointment $appointment
 */
?>
```

Observed types are accumulated across renders, so a variable seen first as `null` and later as an object becomes
`null|\App\Domain\Appointment` instead of being frozen at `null`.

Written nullable types (`?string`), scalar supertypes (`scalar`, `numeric`, `array-key`), `object`, generics and
parenthesized unions/intersections are preserved when they already cover a runtime observation. Use fully qualified
class names in managed blocks: the merger does not resolve template namespaces or `use` aliases, so `User` and
`\App\Domain\User` remain distinct. Guessing from a short name could hide a genuinely different observed class.

For explicit control:

```php
$view->docblocks(\Kaly\Tpl\TemplateDocblocker::ensureOnly());
$view->docblocks(\Kaly\Tpl\TemplateDocblocker::syncManaged());
$view->docblocks(null);
```

- `ensureOnly()` creates the managed block when missing but never rewrites an existing one.
- `syncManaged()` keeps it up to date on every debug render.
- `null` disables the feature.

The header is injected inside the existing `<?php` block, before `declare(strict_types=1)`, so strict-types templates stay
executable.
After a rewrite, the template's OPcache entry is invalidated so the next include sees the updated file.

This feature writes to template files and is only run when debug mode is enabled.
