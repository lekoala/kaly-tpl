# Changelog

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
