<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Formats common display values according to project conventions.
 *
 * Formatting is separate from templates so dates, numbers and money do not end up
 * hard-coded as `->format()` and `number_format()` calls in view files.
 */
interface ValueFormatter
{
    public function date(\DateTimeInterface|string|null $value, string $style = 'medium'): string;

    public function time(\DateTimeInterface|string|null $value, string $style = 'short'): string;

    public function datetime(\DateTimeInterface|string|null $value, string $style = 'medium'): string;

    public function money(int|float|string|null $amount, ?string $currency = null): string;

    public function number(int|float|string|null $value, int $decimals = 0): string;

    /**
     * Format a ratio as a percentage. Example: 0.42 => 42%.
     */
    public function percent(int|float|string|null $value, int $decimals = 0): string;
}
