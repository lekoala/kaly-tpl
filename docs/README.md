# Kaly Tpl documentation

Detailed usage lives here so the [README](../README.md) stays short.

- [Templates and runtime](templates.md) — `$v`, isolated scopes, reserved names, engine options.
- [Escaping](escaping.md) — `e`, `attr`, `url`, `json`, `attrs`, and how to compose them safely.
- [Formatting](formatting.md) — dates, numbers, money, and custom escaper/formatter strategies.
- [Includes and loops](includes-and-loops.md) — `inc()`, `each()`, loop metadata and recursion.
- [Layouts, blocks and stacks](layouts.md) — layout selection, passing data to layouts, captures.
- [Namespaced paths](namespaces.md) — multiple view roots and existence checks.
- [Errors](errors.md) — the template include chain exposed by `ViewException`.
- [Template PHPDoc DX](phpdoc-dx.md) — development-time managed docblocks.
- [Recipes](recipes.md) — typed application helpers, component/slot patterns, engine lifetime.
