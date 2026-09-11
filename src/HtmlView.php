<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Small template-facing API exposed as the reserved `$v` variable.
 *
 * The interface deliberately groups rendering, escaping, formatting and layout helpers
 * so templates get one typed object instead of global helper functions.
 */
interface HtmlView
{
    public function e(mixed $value): string;

    public function attr(mixed $value): string;

    public function url(string|\Stringable|null $value): string;

    /** @param array<string, mixed> $attributes */
    public function attrs(array $attributes): Html;

    public function classes(mixed ...$values): string;

    public function json(mixed $value): Html;

    /** @param array<string, scalar|null> $vars */
    public function styleVars(array $vars): Html;

    public function html(string $trustedHtml): Html;

    public function raw(Html $html): Html;

    public function date(\DateTimeInterface|string|null $value, string $style = 'medium'): Html;

    public function time(\DateTimeInterface|string|null $value, string $style = 'short'): Html;

    public function datetime(\DateTimeInterface|string|null $value, string $style = 'medium'): Html;

    public function money(int|float|string|null $amount, ?string $currency = null): Html;

    public function number(int|float|string|null $value, int $decimals = 0): Html;

    /** Format a ratio as a percentage. Example: 0.42 => 42%. */
    public function percent(int|float|string|null $value, int $decimals = 0): Html;

    /** @param array<string, mixed> $data */
    public function inc(string $template, array $data = []): Html;

    /**
     * Render each item through an isolated item template.
     *
     * The common form keeps the template call short:
     *
     *     $v->each($items, 'item/card', as: 'item')
     *
     * Pass {@see EachOptions} as the third argument for advanced options such as recursive depth.
     *
     * @param iterable<mixed, mixed> $items
     * @param array<string, mixed> $data Extra data passed to every item template when using the short form.
     */
    public function each(
        iterable $items,
        string $template,
        string|EachOptions $as = 'item',
        ?string $empty = null,
        array $data = [],
    ): Html;

    public function layout(string $template): void;

    public function title(?string $title = null): string;

    public function start(string $name): void;

    public function push(string $name): void;

    public function end(): void;

    public function block(string $name, string|Html $default = ''): Html;

    public function stack(string $name): Html;

    public function content(): Html;
}
