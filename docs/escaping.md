# Escaping

Escaping is explicit. There is no PHP parser attempting to infer the output context, so it is up to the template to pick
the helper matching the destination.

```php
<?= $v->e($text) ?>              <!-- text node -->
<?= $v->attr($value) ?>          <!-- inside a quoted attribute -->
<a href="<?= $v->url($url) ?>">Profile</a>
<?= $v->json($payload) ?>        <!-- JSON inside a <script> block -->
```

The default URL helper accepts relative URLs and the `http`, `https`, `mailto` and `tel` schemes. Unsafe schemes,
protocol-relative URLs and URLs containing ASCII control characters are replaced with `#`.
Two leading slashes or backslashes (including mixed pairs) are treated as protocol-relative and rejected. A single
leading backslash remains an origin-relative path in HTTP(S) contexts; it does not select an external host.

## Attribute bags

Attribute bags handle escaping and HTML boolean attributes in one place:

```php
<button<?= $v->attrs([
    'class' => ['btn', 'active' => $active],
    'disabled' => $disabled,
    'aria-expanded' => $expanded,
    'data-id' => $id,
]) ?>>
    Save
</button>
```

- HTML boolean attributes such as `disabled`, `required` and `checked` are emitted by presence.
- Other boolean values, including ARIA and data attributes, are rendered as `"true"` or `"false"`.
- Common URL attributes such as `href`, `src` and `action` are passed through the URL policy automatically.
- Inline event-handler attributes and `srcdoc` are rejected by `attrs()` because they require a different output context.
- `srcset` and `imagesrcset` are validated as complete candidate lists before refused URLs are dropped. Invalid
  descriptors, duplicate descriptors (including implicit `1x`) and mixed width/density descriptors throw even when
  a candidate's URL would be refused. For example, `javascript:x 1x, /photo.jpg 1x` throws for the duplicate.
- `ping` keeps only HTTP(S) URLs, including relative URLs. URL-list attributes are omitted when no URL remains.

## Composing escaping helpers

The helpers target different contexts. Mixing them is the usual source of double escaping.

| Helper | Target context | Already escaped? |
| --- | --- | --- |
| `e()` | text node | yes |
| `attr()` | quoted attribute value | yes |
| `url()` | URL attribute value | yes |
| `json()` | `<script>` block | usable as-is (JSON, not HTML) |
| `classes()` | `class="..."` | yes |
| `styleVars()` | `style="..."` | yes |
| `attrs()` | a whole attribute bag | yes |

### Do not re-escape `classes()` / `styleVars()`

`classes()` and `styleVars()` already return escaped strings. Pass the raw values to `attrs()` instead of their rendered
output:

```php
<?php // Correct: let attrs() do the escaping. ?>
<div<?= $v->attrs(['class' => ['card', 'active' => $active], 'style' => ['--progress' => $progress]]) ?>>

<?php // Wrong: the class list gets escaped twice. ?>
<div<?= $v->attrs(['class' => $v->classes('card', 'active')]) ?>>
```

Echo them directly only when they are the entire attribute value:

```php
<div class="<?= $v->classes('card', ['selected' => $selected]) ?>"
     style="<?= $v->styleVars(['--progress' => $progress]) ?>">
```

`styleVars()` and an array passed as `attrs(['style' => [...]])` only build CSS custom-property declarations. They
validate property names and reject `;`, `{` and `}` in values, then escape the result for the HTML attribute.
They do not sanitize CSS values: functions such as `url(...)` and CSS escapes can still cause network requests when
the variable is used. Validate values against application-specific rules (for example a numeric range or a color allowlist).

A string or `Stringable` passed as `attrs(['style' => $css])` receives HTML attribute escaping only, with no CSS
validation. Use application-controlled CSS for this form. HTML escaping alone does not make arbitrary user CSS safe.

### JSON in a script vs JSON in an attribute

`json()` produces JSON for a `<script>` block. It is not an attribute escaper:

```php
<script type="application/json"><?= $v->json($payload) ?></script>
```

For JSON carried by a `data-*` attribute, encode it and run it through the attribute escaper:

```php
<div data-config="<?= $v->attr(json_encode($payload, JSON_THROW_ON_ERROR)) ?>">
```

### Conditional attributes

Prefer the attribute bag over manual string concatenation:

```php
<input<?= $v->attrs([
    'type' => 'text',
    'value' => $value,
    'required' => $required,
    'aria-invalid' => $invalid,
]) ?>>
```

### Trusted markup

For deliberately trusted markup, use an explicit `Html` value:

```php
<?= $v->html('<strong>Trusted application markup</strong>') ?>
```

`$v->raw($html)` re-emits an existing `Html` value without re-escaping.
