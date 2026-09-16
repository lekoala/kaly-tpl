<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Deterministic, locale-neutral duration formatting.
 *
 * `NumberFormatter::DURATION` is intentionally not used: its output varies with the
 * locale (for example "45 sec." in `en`), which defeats a deterministic duration.
 *
 * @internal Used by {@see DefaultValueFormatter}; not part of the public API.
 */
final class DurationFormatter
{
    public static function intervalSeconds(\DateInterval $interval): float
    {
        if ($interval->days === false && ($interval->y + $interval->m) !== 0) {
            throw new \InvalidArgumentException(
                'DateInterval with years or months is calendar-relative and cannot be formatted as a duration.',
            );
        }

        $days = $interval->days === false ? $interval->d : $interval->days;
        $seconds = ($days * 86_400) + ($interval->h * 3_600) + ($interval->i * 60) + $interval->s + $interval->f;

        return $interval->invert === 1 ? -$seconds : $seconds;
    }

    public static function render(float $seconds, string $style): string
    {
        $total = (int) round(abs($seconds));
        $sign = $seconds < 0 && $total > 0 ? '-' : '';

        return $sign . match ($style) {
            'short' => self::clock($total),
            'long' => self::units($total),
            default => throw new \InvalidArgumentException(sprintf('Unknown duration style "%s".', $style)),
        };
    }

    private static function clock(int $total): string
    {
        $hours = intdiv($total, 3_600);
        $minutes = intdiv($total % 3_600, 60);
        $seconds = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds)
            : sprintf('%d:%02d', $minutes, $seconds);
    }

    private static function units(int $total): string
    {
        if ($total === 0) {
            return '0 s';
        }

        $parts = [];
        foreach (['d' => 86_400, 'h' => 3_600, 'min' => 60, 's' => 1] as $unit => $size) {
            $count = intdiv($total, $size);
            if ($count > 0) {
                $parts[] = $count . ' ' . $unit;
                $total %= $size;
            }
        }

        return implode(' ', $parts);
    }
}
