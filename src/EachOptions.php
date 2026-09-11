<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Advanced options for {@see HtmlView::each()}.
 *
 * Most templates can keep using the short signature:
 *
 *     $v->each($items, 'item/card', as: 'item')
 *
 * Use this object for less common options such as recursive depth metadata.
 */
final readonly class EachOptions
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $as = 'item',
        public ?string $empty = null,
        public array $data = [],
        public int $depth0 = 0,
        public int $maxDepth = 50,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $as)) {
            throw new \InvalidArgumentException(sprintf('Invalid item variable name "%s".', $as));
        }
        if ($as === 'v' || $as === 'key' || $as === 'loop' || str_starts_with($as, '__kaly')) {
            throw new \InvalidArgumentException(sprintf('Item variable name "%s" is reserved.', $as));
        }
        if ($depth0 < 0) {
            throw new \InvalidArgumentException('Loop depth cannot be negative.');
        }
        if ($maxDepth < 0) {
            throw new \InvalidArgumentException('Maximum loop depth cannot be negative.');
        }
        if ($depth0 > $maxDepth) {
            throw new \LogicException(sprintf('Maximum loop depth of %d exceeded.', $maxDepth));
        }
    }

    public static function as(string $name): self
    {
        return new self(as: $name);
    }

    public function empty(?string $template): self
    {
        return new self($this->as, $template, $this->data, $this->depth0, $this->maxDepth);
    }

    /** @param array<string, mixed> $data */
    public function data(array $data): self
    {
        return new self($this->as, $this->empty, $data, $this->depth0, $this->maxDepth);
    }

    public function depth0(int $depth0): self
    {
        return new self($this->as, $this->empty, $this->data, $depth0, $this->maxDepth);
    }

    public function maxDepth(int $maxDepth): self
    {
        return new self($this->as, $this->empty, $this->data, $this->depth0, $maxDepth);
    }
}
