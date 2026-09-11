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
 * Use this object for advanced options such as a shared empty template or extra data:
 *
 *     EachOptions::as('user')->empty('users/empty')->data(['compact' => true])
 *
 * For recursive views, continue from the current loop with {@see Loop::nested()}.
 */
final readonly class EachOptions
{
    /**
     * @param array<string, mixed> $data
     *
     * `baseDepth` and `maxDepth` are managed by the engine. Continue a recursive loop with
     * {@see Loop::nested()} instead of setting them directly.
     */
    public function __construct(
        public string $as = 'item',
        public ?string $empty = null,
        public array $data = [],
        public int $baseDepth = 0,
        public int $maxDepth = 50,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $as)) {
            throw new \InvalidArgumentException(sprintf('Invalid item variable name "%s".', $as));
        }
        if ($as === 'v' || $as === 'key' || $as === 'loop' || str_starts_with($as, '__kaly')) {
            throw new \InvalidArgumentException(sprintf('Item variable name "%s" is reserved.', $as));
        }
        if ($baseDepth < 0) {
            throw new \InvalidArgumentException('Loop depth cannot be negative.');
        }
        if ($maxDepth < 0) {
            throw new \InvalidArgumentException('Maximum loop depth cannot be negative.');
        }
        if ($baseDepth >= $maxDepth) {
            throw new \LogicException(sprintf('Maximum loop depth of %d exceeded.', $maxDepth));
        }
    }

    public static function as(string $name): self
    {
        return new self(as: $name);
    }

    public function empty(?string $template): self
    {
        return new self($this->as, $template, $this->data, $this->baseDepth, $this->maxDepth);
    }

    /** @param array<string, mixed> $data */
    public function data(array $data): self
    {
        return new self($this->as, $this->empty, $data, $this->baseDepth, $this->maxDepth);
    }

    public function maxDepth(int $maxDepth): self
    {
        return new self($this->as, $this->empty, $this->data, $this->baseDepth, $maxDepth);
    }
}
