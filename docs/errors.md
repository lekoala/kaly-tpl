# Errors

When a template fails, `render()` throws `Kaly\Tpl\ViewException`. It keeps the original exception as `previous` and
exposes the resolved include chain:

```php
use Kaly\Tpl\ViewException;

try {
    echo $view->render('users/index');
} catch (ViewException $exception) {
    $exception->getMessage();      // 'avatar failed (template chain: users/index > users/card > partials/avatar)'
    $exception->templateStack();   // ['users/index', 'users/card', 'partials/avatar']
    $exception->getPrevious();     // the original RuntimeException
}
```

The chain includes every resolved template from the top-level view down to the one that failed, across `inc()`, `each()`
and layout renders.

## What is wrapped

`ViewException` extends `\RuntimeException`. The engine only wraps "unexpected" failures:

- template runtime errors (`\RuntimeException`, `\Error`, application exceptions, …);
- failures inside partials and layouts.

It deliberately does **not** wrap the two exception types the engine uses for configuration and usage errors, so existing
`catch` blocks keep working:

- `\LogicException` — unclosed capture, layouts selected from a partial, circular layout chain, maximum depth;
- `\InvalidArgumentException` — invalid template names, reserved data names, invalid view paths.

This keeps the include chain focused on render-time failures while preserving the sharp, specific errors for engine
misuse.

## Debug-only template headers

The include chain is derived from the templates the engine actually resolved at runtime. It needs no debug mode. The
managed PHPDoc headers described in [Template PHPDoc DX](phpdoc-dx.md) are unrelated and only run in debug mode.
