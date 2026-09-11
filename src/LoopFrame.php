<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Immutable loop frame for the current iteration.
 *
 * It is intentionally internal-ish: templates interact with {@see Loop} instead.
 */
final readonly class LoopFrame
{
    public function __construct(
        public int $index0,
        public mixed $key,
        public bool $last,
        public mixed $previous,
        public mixed $next,
    ) {}
}
