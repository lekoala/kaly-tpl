<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Escaping strategy used by the template runtime.
 *
 * Implementations should be template-friendly: `null` should usually render as an
 * empty string, while non-stringable objects should fail loudly.
 */
interface Escaper
{
    public function html(mixed $value): string;

    public function attr(mixed $value): string;

    public function url(string|\Stringable|null $value): string;

    public function json(mixed $value): Html;

    /** @param array<string, mixed> $attributes */
    public function attrs(array $attributes): Html;

    public function classes(mixed ...$values): string;

    /** @param array<string, scalar|null> $vars */
    public function styleVars(array $vars): Html;
}
