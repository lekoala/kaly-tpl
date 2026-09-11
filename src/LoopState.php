<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Per-loop mutable state used by helpers such as {@see Loop::changed()}.
 */
final class LoopState
{
    /** @var array<string, mixed> */
    private array $changed = [];

    public function changed(string $key, mixed $value): bool
    {
        if (!array_key_exists($key, $this->changed) || $this->changed[$key] !== $value) {
            $this->changed[$key] = $value;
            return true;
        }

        return false;
    }
}
