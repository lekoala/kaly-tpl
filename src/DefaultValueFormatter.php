<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Default formatter using ext-intl when available, with deterministic neutral fallbacks.
 */
final readonly class DefaultValueFormatter implements ValueFormatter
{
    public function __construct(
        private string $locale = 'en',
        private string $currency = 'EUR',
        private ?\DateTimeZone $timezone = null,
    ) {}

    public function date(\DateTimeInterface|string|null $value, string $style = 'medium'): string
    {
        $date = $this->dateValue($value);
        if ($date === null) {
            return '';
        }

        return $this->formatDateTime($date, $style, 'none', 'Y-m-d');
    }

    public function time(\DateTimeInterface|string|null $value, string $style = 'short'): string
    {
        $date = $this->dateValue($value);
        if ($date === null) {
            return '';
        }

        return $this->formatDateTime($date, 'none', $style, match ($style) {
            'medium', 'long', 'full' => 'H:i:s',
            default => 'H:i',
        });
    }

    public function datetime(\DateTimeInterface|string|null $value, string $style = 'medium'): string
    {
        $date = $this->dateValue($value);
        if ($date === null) {
            return '';
        }

        return $this->formatDateTime($date, $style, 'short', 'Y-m-d H:i');
    }

    public function money(int|float|string|null $amount, ?string $currency = null): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        $currency ??= $this->currency;
        $value = $this->numericValue($amount);

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($this->locale, \NumberFormatter::CURRENCY);
            $result = $formatter->formatCurrency($value, $currency);
            if ($result !== false) {
                return $result;
            }
        }

        return number_format($value, 2, '.', ',') . ' ' . $currency;
    }

    public function number(int|float|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $this->assertDecimals($decimals);
        $number = $this->numericValue($value);

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $result = $formatter->format($number);
            if ($result !== false) {
                return $result;
            }
        }

        return number_format($number, $decimals, '.', ',');
    }

    public function percent(int|float|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $this->assertDecimals($decimals);
        $ratio = $this->numericValue($value);

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($this->locale, \NumberFormatter::PERCENT);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $result = $formatter->format($ratio);
            if ($result !== false) {
                return $result;
            }
        }

        return number_format($ratio * 100, $decimals, '.', ',') . '%';
    }

    public function duration(\DateInterval|int|float|string|null $value, string $style = 'short'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $seconds = $value instanceof \DateInterval
            ? DurationFormatter::intervalSeconds($value)
            : $this->numericValue($value);

        return DurationFormatter::render($seconds, $style);
    }

    private function dateValue(\DateTimeInterface|string|null $value): ?\DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($value)
            : new \DateTimeImmutable($value, $this->timezone);

        if ($this->timezone === null) {
            return $date;
        }

        return $date->setTimezone($this->timezone);
    }

    private function formatDateTime(
        \DateTimeInterface $date,
        string $dateStyle,
        string $timeStyle,
        string $fallback,
    ): string {
        if (class_exists(\IntlDateFormatter::class)) {
            // Passing the object also supports PHP fixed-offset timezones such as +00:00.
            $timezone = $this->timezone ?? $date->getTimezone();

            $formatter = new \IntlDateFormatter(
                $this->locale,
                $this->intlStyle($dateStyle),
                $this->intlStyle($timeStyle),
                $timezone,
            );

            $result = $formatter->format($date);
            if ($result !== false) {
                return $result;
            }
        }

        return $date->format($fallback);
    }

    private function intlStyle(string $style): int
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return -1;
        }

        return match ($style) {
            'none' => \IntlDateFormatter::NONE,
            'short' => \IntlDateFormatter::SHORT,
            'long' => \IntlDateFormatter::LONG,
            'full' => \IntlDateFormatter::FULL,
            default => \IntlDateFormatter::MEDIUM,
        };
    }

    private function numericValue(int|float|string $value): float
    {
        if (is_string($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('Expected a numeric value, got "%s".', $value));
        }

        $number = (float) $value;
        if (!is_finite($number)) {
            throw new \InvalidArgumentException('Numeric value must be finite.');
        }

        return $number;
    }

    private function assertDecimals(int $decimals): void
    {
        if ($decimals < 0) {
            throw new \InvalidArgumentException('Decimal places cannot be negative.');
        }
    }
}
