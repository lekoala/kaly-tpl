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

`ViewException` extends `\RuntimeException`. The decision depends on where the exception comes from, not on its class.

Every exception thrown by application code is wrapped, whatever its type: a `\DomainException`, an HTTP exception, an
`\Error`, a `\ValueError` from a formatter… The same goes for engine runtime failures such as a missing view.

Usage errors raised by the engine itself are **not** wrapped, so the specific error reaches the caller directly. They
keep the standard SPL parents, so existing `catch` blocks keep working:

- `Kaly\Tpl\ViewLogicException` (a `\LogicException`) — unclosed capture, layouts selected from a partial, circular
  layout chain, maximum loop, layout or template depth;
- `Kaly\Tpl\ViewUsageException` (an `\InvalidArgumentException`) — invalid template names, reserved data names, invalid
  view paths, invalid escaping or formatting input, collisions with shared render data (the message itself includes the
  template chain).

All engine exceptions, `ViewException` included, implement `Kaly\Tpl\ExceptionInterface`.

### Unwrapping application exceptions

An error handler that maps exception classes to HTTP statuses must look at the previous exception:

```php
try {
    echo $view->render('users/show', ['user' => $user]);
} catch (ViewException $exception) {
    throw $exception->getPrevious() ?? $exception; // e.g. rethrow the original 404
}
```

## Recursive includes

A render nests at most 256 templates through `inc()` and `each()`. A partial that includes itself, directly
or through a cycle, fails with a `ViewLogicException` that shows the end of the chain. Without that limit it would end in
an uncatchable memory exhaustion fatal error.

## Debug-only template headers

The include chain is derived from the templates the engine actually resolved at runtime. It needs no debug mode. The
managed PHPDoc headers described in [Template PHPDoc DX](phpdoc-dx.md) are unrelated and only run in debug mode.
