<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Default formatter using ext-intl when available, with deterministic neutral fallbacks.
 */
final readonly class DefaultValueFormatter implements ValueFormatter
{
    /** ICU locale identifier capacity (ULOC_FULLNAME_CAPACITY). */
    private const MAX_LOCALE_LENGTH = 157;

    private const DATE_STYLES = ['short', 'medium', 'long', 'full'];

    /**
     * ICU formatters are expensive to build, so they are reused across calls.
     *
     * @var \ArrayObject<string, \NumberFormatter>
     */
    private \ArrayObject $numberFormatters;

    /** @var \ArrayObject<string, \IntlDateFormatter> */
    private \ArrayObject $dateFormatters;

    public function __construct(
        private string $locale = 'en',
        private string $currency = 'EUR',
        private ?\DateTimeZone $timezone = null,
    ) {
        // ICU accepts almost any string, then fails or silently falls back at first use.
        if (
            strlen($locale) > self::MAX_LOCALE_LENGTH
            || !preg_match('/^[A-Za-z]{2,8}([_-][A-Za-z0-9]{1,8})*(@[A-Za-z0-9=;_-]+)?$/', $locale)
        ) {
            throw new ViewUsageException(sprintf('Invalid locale "%s".', $locale));
        }

        /** @var \ArrayObject<string, \NumberFormatter> $numberFormatters */
        $numberFormatters = new \ArrayObject();
        /** @var \ArrayObject<string, \IntlDateFormatter> $dateFormatters */
        $dateFormatters = new \ArrayObject();

        $this->numberFormatters = $numberFormatters;
        $this->dateFormatters = $dateFormatters;
    }

    public function date(\DateTimeInterface|string|null $value, string $style = 'medium'): string
    {
        $this->assertDateStyle($style);
        $date = $this->dateValue($value);
        if ($date === null) {
            return '';
        }

        return $this->formatDateTime($date, $style, 'none', 'Y-m-d');
    }

    public function time(\DateTimeInterface|string|null $value, string $style = 'short'): string
    {
        $this->assertDateStyle($style);
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
        $this->assertDateStyle($style);
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
            $formatter = $this->numberFormatter(\NumberFormatter::CURRENCY);
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
            $formatter = $this->numberFormatter(\NumberFormatter::DECIMAL, $decimals);
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
            $formatter = $this->numberFormatter(\NumberFormatter::PERCENT, $decimals);
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
            // dateValue() already applied the configured timezone. ICU only knows
            // region identifiers: offsets (+05:45) and abbreviations (Z, BST, CEST)
            // are pinned to the offset of this instant, keeping the PHP wall time.
            $timezone = new \DateTimeZone($date->format('e'));
            if ($timezone->getLocation() === false) {
                $timezone = new \DateTimeZone($date->format('P'));
            }

            $result = $this->dateFormatter($dateStyle, $timeStyle, $timezone)->format($date);
            if ($result !== false) {
                return $result;
            }
        }

        return $date->format($fallback);
    }

    private function numberFormatter(int $style, ?int $decimals = null): \NumberFormatter
    {
        $key = $style . ':' . ($decimals ?? '');
        $formatter = $this->numberFormatters[$key] ?? null;
        if ($formatter === null) {
            $formatter = new \NumberFormatter($this->locale, $style);
            if ($decimals !== null) {
                $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            }
            $this->numberFormatters[$key] = $formatter;
        }

        return $formatter;
    }

    private function dateFormatter(string $dateStyle, string $timeStyle, \DateTimeZone $timezone): \IntlDateFormatter
    {
        $key = $dateStyle . ':' . $timeStyle . ':' . $timezone->getName();
        $formatter = $this->dateFormatters[$key] ?? null;
        if ($formatter === null) {
            $formatter = new \IntlDateFormatter(
                $this->locale,
                $this->intlStyle($dateStyle),
                $this->intlStyle($timeStyle),
                $timezone,
            );
            $this->dateFormatters[$key] = $formatter;
        }

        return $formatter;
    }

    private function assertDateStyle(string $style): void
    {
        if (!in_array($style, self::DATE_STYLES, true)) {
            throw new ViewUsageException(sprintf('Unknown date style "%s".', $style));
        }
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
            throw new ViewUsageException(sprintf('Expected a numeric value, got "%s".', $value));
        }

        $number = (float) $value;
        if (!is_finite($number)) {
            throw new ViewUsageException('Numeric value must be finite.');
        }

        return $number;
    }

    private function assertDecimals(int $decimals): void
    {
        if ($decimals < 0) {
            throw new ViewUsageException('Decimal places cannot be negative.');
        }
    }
}
