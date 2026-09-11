<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Metadata made available to item templates rendered through {@see HtmlView::each()}.
 *
 * `last()` is available even for generators because the renderer reads one item ahead.
 * `length()` is only available for arrays and countable iterables.
 */
final readonly class Loop
{
    public function __construct(
        private LoopFrame $frame,
        private ?int $length,
        private int $depth0,
        private LoopState $state,
    ) {}

    public function index0(): int
    {
        return $this->frame->index0;
    }

    public function index(): int
    {
        return $this->frame->index0 + 1;
    }

    public function key(): mixed
    {
        return $this->frame->key;
    }

    public function first(): bool
    {
        return $this->frame->index0 === 0;
    }

    public function last(): bool
    {
        return $this->frame->last;
    }

    public function hasLength(): bool
    {
        return $this->length !== null;
    }

    public function length(): int
    {
        if ($this->length === null) {
            throw new \LogicException('Loop length is only available for countable iterables.');
        }

        return $this->length;
    }

    public function hasPrevious(): bool
    {
        return $this->frame->index0 > 0;
    }

    public function previous(): mixed
    {
        return $this->frame->previous;
    }

    public function hasNext(): bool
    {
        return !$this->frame->last;
    }

    public function next(): mixed
    {
        return $this->frame->next;
    }

    public function depth(): int
    {
        return $this->depth0 + 1;
    }

    public function depth0(): int
    {
        return $this->depth0;
    }

    public function odd(): bool
    {
        return ($this->index() % 2) === 1;
    }

    public function even(): bool
    {
        return !$this->odd();
    }

    public function cycle(mixed ...$values): mixed
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Loop cycle requires at least one value.');
        }

        return $values[$this->frame->index0 % count($values)];
    }

    /**
     * Returns true once when a named value changes during the loop.
     *
     * The key is explicit because PHP templates do not expose a stable expression call-site.
     */
    public function changed(string $key, mixed $value): bool
    {
        return $this->state->changed($key, $value);
    }
}
