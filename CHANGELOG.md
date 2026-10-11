# Changelog

## Unreleased

### Added

- Engine exceptions: `ViewUsageException` (an `\InvalidArgumentException`) and `ViewLogicException` (a
  `\LogicException`), both implementing the `ExceptionInterface` marker, like `ViewException`.

### Changed

- **Breaking:** a string default passed to `$v->block($name, $default)` is now escaped. Pass `$v->html(...)` for markup.
- **Breaking:** `ViewException` now wraps every application exception, whatever its class (`\DomainException`,
  HTTP exceptions, …). Only engine exceptions pass through unwrapped. Use `getPrevious()` to get the original.
- ext-intl number and date formatters are reused across calls, about 15 to 20 times faster in formatted lists.
- Resolved template files are cached per engine (except in debug mode), about twice as fast for `each()` on Windows.
- `each()` data can no longer contain the item variable, `key` or `loop` (previously silently overridden).
- Unknown date styles throw instead of falling back to `medium`.
- Invalid locales are rejected by `locale()` / `DefaultValueFormatter` instead of failing at first render.
- `ext-intl` is listed in `suggest`.
- Dev dependencies: removed `composer/ca-bundle`, PHPStan `^2.3`, Mago `^1.48`.
- CI also runs the tests without ext-intl and with the lowest dependencies.

### Fixed

- A recursive include now throws a `ViewLogicException` after 256 nested templates, instead of exhausting memory.

## 0.2.1

### Fixed

- Date, time and datetime formatting accepts fixed-offset timezones such as
  `+00:00`, `+05:45` and `-03:30` with ext-intl, both on input dates and as the
  configured display timezone, preserving the offset instead of raising an exception.
- Abbreviated timezones keep PHP's wall time with ext-intl. ISO strings ending in
  `Z` and abbreviations such as `CEST` or `PDT` no longer raise an `IntlException`,
  and ambiguous ones such as `BST` (British Summer Time for PHP, Bangladesh for ICU)
  no longer shift the displayed time.

## 0.2.0

### Added

- **Render-scoped shared data.** `ViewEngine::render()` accepts `sharedData:` for values that must be available to the
  root template, its layouts, its includes and its `each()` templates without becoming mutable engine globals. Shared
  data never leaks between renders, including after a failed render.
- **`duration()` formatting.** `$v->duration()` formats seconds or a fixed-length `DateInterval` as `1:02:03` (`short`)
  or `1 h 2 min 3 s` (`long`). Output is locale-neutral by design; calendar-relative intervals are rejected.

### Changed

- **Shared data cannot be shadowed.** A name present in `sharedData` cannot also be a global, nor be redefined by any
  template of the same render: root `$data`, `inc()` / `layout()` data, the layout `content`, or the `each()` item,
  `key` and `loop` variables. Collisions throw an `\InvalidArgumentException` naming the variables and the template
  chain. Locals still override globals, as before.
- **Breaking for custom implementations:** `ValueFormatter` and `HtmlView` gained a `duration()` method.
- Globals and shared data are validated once (by `addGlobal()` and `render()`) instead of once per rendered template.
- `ViewEngine::renderPartial()` is now marked `@internal`; it is not a consumer API.

### Fixed

- **Reentrant rendering.** The template and failure stacks are now held by the per-render context, so a nested or
  interleaved `render()` on the same engine can no longer corrupt another render's template chain.
- An earlier recipe implied that render `$data` propagated to partials and layouts. It never did: render `$data` is local
  to the root template. Use `sharedData:` for render-wide values.

### Docs

- New "Inline loops" section: prefer a native `foreach` for markup that belongs to the current template, and `each()` when
  the item is naturally its own template.
- Scope table for globals, render data, shared data, include data and layout data.
