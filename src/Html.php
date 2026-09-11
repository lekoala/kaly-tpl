<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Represents trusted HTML produced by the renderer or explicitly marked as safe.
 */
final readonly class Html implements \Stringable
{
    public function __construct(
        private string $html,
    ) {}

    public static function trusted(string $html): self
    {
        return new self($html);
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
