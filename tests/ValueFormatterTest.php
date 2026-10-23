<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\DefaultValueFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueFormatterTest extends TestCase
{
    public function testNullAndEmptyValuesRenderAsEmptyStrings(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->assertSame('', $formatter->date(null));
        $this->assertSame('', $formatter->number(''));
        $this->assertSame('', $formatter->money(null));
        $this->assertSame('', $formatter->percent(''));
    }

    public function testInvalidNumericStringsAreRejectedInsteadOfBecomingZero(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->expectException(\InvalidArgumentException::class);
        $formatter->money('not-a-number');
    }

    public function testNonFiniteNumbersAreRejected(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->expectException(\InvalidArgumentException::class);
        $formatter->number(INF);
    }

    public function testNegativeDecimalPlacesAreRejected(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->expectException(\InvalidArgumentException::class);
        $formatter->percent(0.42, -1);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLocales(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['not a locale'];
        yield 'path' => ['../x'];
        yield 'too long' => [str_repeat('a', 200)];
        yield 'bare keyword' => ['en_US@foo'];
        yield 'empty keyword name' => ['en_US@=x'];
        yield 'empty keyword value' => ['en@currency='];
        yield 'trailing separator' => ['en@currency=EUR;'];
    }

    #[DataProvider('invalidLocales')]
    public function testInvalidLocalesAreRejectedUpFront(string $locale): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid locale');

        new DefaultValueFormatter($locale);
    }

    public function testValidLocaleShapesAreAcceptedAndRender(): void
    {
        $locales = ['en', 'fr_BE', 'fr-BE', 'zh_Hant_TW', 'en_US@currency=EUR', 'th_TH@calendar=buddhist;numbers=latn'];
        $date = new \DateTimeImmutable('2026-01-01');

        foreach ($locales as $locale) {
            // Formatting a real value builds the ICU formatters, which is where a bad locale fails.
            $formatter = new DefaultValueFormatter($locale);
            $this->assertNotSame('', $formatter->number(1));
            $this->assertNotSame('', $formatter->date($date));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCurrencies(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['EU'];
        yield 'too long' => ['INVALID'];
        yield 'digits' => ['123'];
        yield 'non ASCII' => ['ÉUR'];
        yield 'trailing newline' => ["EUR\n"];
    }

    #[DataProvider('invalidCurrencies')]
    public function testInvalidDefaultCurrenciesAreRejected(string $currency): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid currency');

        new DefaultValueFormatter(currency: $currency);
    }

    #[DataProvider('invalidCurrencies')]
    public function testInvalidCurrencyOverridesAreRejected(string $currency): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid currency');

        (new DefaultValueFormatter())->money(10, $currency);
    }

    public function testMoneyAcceptsValidCurrencyShapes(): void
    {
        $formatter = new DefaultValueFormatter(currency: 'eur');

        $this->assertNotSame('', $formatter->money(10));
        $this->assertNotSame('', $formatter->money(10, 'USD'));
        $this->assertNotSame('', $formatter->money(10, 'JPY'));
    }

    /** @return iterable<string, array{int|float|string}> */
    public static function overflowingDurations(): iterable
    {
        yield 'integer maximum rounded as float' => [PHP_INT_MAX];
        yield 'negative integer maximum' => [-PHP_INT_MAX];
        yield 'huge positive' => [1e20];
        yield 'huge negative string' => ['-1e20'];
        yield 'largest finite float' => [PHP_FLOAT_MAX];
    }

    #[DataProvider('overflowingDurations')]
    public function testDurationsOutsideIntegerRangeAreRejected(int|float|string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration is too large');

        (new DefaultValueFormatter())->duration($value);
    }

    public function testLargeRepresentableDurationsStillRender(): void
    {
        $seconds = PHP_INT_SIZE === 8 ? 9_223_372_036_854_774_784 : PHP_INT_MAX - 1;
        $expected = sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);

        $this->assertSame($expected, (new DefaultValueFormatter())->duration($seconds));
    }

    public function testUnknownDateStylesAreRejected(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown date style "meduim".');

        $formatter->date('2026-01-01', 'meduim');
    }

    public function testReusedFormattersKeepPerCallOptions(): void
    {
        $formatter = new DefaultValueFormatter('en');
        $date = new \DateTimeImmutable('2026-01-01T23:30:00+00:00');
        $brussels = (new DefaultValueFormatter('en', timezone: new \DateTimeZone('Europe/Brussels')))->time($date);
        $tokyo = (new DefaultValueFormatter('en', timezone: new \DateTimeZone('Asia/Tokyo')))->time($date);

        $this->assertSame('1,235', $formatter->number(1234.56));
        $this->assertSame('1,234.56', $formatter->number(1234.56, 2));
        $this->assertSame('1,235', $formatter->number(1234.56));
        $this->assertSame('42%', $formatter->percent(0.42));
        $this->assertSame('42.0%', $formatter->percent(0.42, 1));
        $this->assertSame($formatter->time($date->setTimezone(new \DateTimeZone('Europe/Brussels'))), $brussels);
        $this->assertSame($formatter->time($date->setTimezone(new \DateTimeZone('Asia/Tokyo'))), $tokyo);
        $this->assertNotSame($brussels, $tokyo);
    }

    public function testDurationFormatsSecondsDeterministically(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->assertSame('0:00', $formatter->duration(0));
        $this->assertSame('0:45', $formatter->duration(45));
        $this->assertSame('1:30', $formatter->duration(90));
        $this->assertSame('1:02:03', $formatter->duration(3723));
        $this->assertSame('25:01:01', $formatter->duration(90_061));
        $this->assertSame('-1:30', $formatter->duration(-90));
        $this->assertSame('1:02:03', $formatter->duration('3723'));
    }

    public function testDurationLongStyleSpellsOutUnits(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->assertSame('0 s', $formatter->duration(0, 'long'));
        $this->assertSame('45 s', $formatter->duration(45, 'long'));
        $this->assertSame('1 min 30 s', $formatter->duration(90, 'long'));
        $this->assertSame('1 h 2 min 3 s', $formatter->duration(3723, 'long'));
        $this->assertSame('1 d 1 h 1 min 1 s', $formatter->duration(90_061, 'long'));
    }

    public function testDurationAcceptsFixedIntervals(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->assertSame('1:02:03', $formatter->duration(new \DateInterval('PT1H2M3S')));
        $this->assertSame('24:00:00', $formatter->duration(new \DateInterval('P1D')));

        $diff = (new \DateTimeImmutable('2026-01-01'))->diff(new \DateTimeImmutable('2026-01-03'));
        $this->assertSame('48:00:00', $formatter->duration($diff));
    }

    public function testCalendarRelativeIntervalsAreRejectedAsDurations(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->expectException(\InvalidArgumentException::class);
        $formatter->duration(new \DateInterval('P1M'));
    }

    public function testUnknownDurationStylesAreRejected(): void
    {
        $formatter = new DefaultValueFormatter();

        $this->expectException(\InvalidArgumentException::class);
        $formatter->duration(90, 'medium');
    }

    #[DataProvider('fixedOffsets')]
    public function testDateFormattingPreservesFixedOffsets(string $offset, bool $configured): void
    {
        $timezone = new \DateTimeZone($offset);
        $formatter = new DefaultValueFormatter('en_GB', timezone: $configured ? $timezone : null);
        $immutable = new \DateTimeImmutable('2026-01-01T23:30:00' . ($configured ? '+00:00' : $offset));
        $mutable = \DateTime::createFromImmutable($immutable);
        $original = $immutable->format('c e');
        $local = $immutable->setTimezone($timezone);
        $styles = [
            'date' => ['medium', 'none', 'Y-m-d'],
            'time' => ['none', 'short', 'H:i'],
            'datetime' => ['medium', 'short', 'Y-m-d H:i'],
        ];

        foreach ($styles as $method => [$dateStyle, $timeStyle, $fallback]) {
            $style = $method === 'time' ? $timeStyle : $dateStyle;
            $expected = $local->format($fallback);
            if (class_exists(\IntlDateFormatter::class)) {
                $intlStyles = [
                    'none' => \IntlDateFormatter::NONE,
                    'short' => \IntlDateFormatter::SHORT,
                    'medium' => \IntlDateFormatter::MEDIUM,
                ];
                $expected = (new \IntlDateFormatter(
                    'en_GB',
                    $intlStyles[$dateStyle],
                    $intlStyles[$timeStyle],
                    'GMT' . $offset,
                ))->format($immutable->getTimestamp());
            }
            $this->assertIsString($expected);
            $this->assertNotSame('', $expected);

            foreach ([$immutable, $mutable, $immutable->format('c')] as $value) {
                $this->assertSame($expected, $formatter->$method($value, $style));
            }
        }

        $this->assertSame($original, $mutable->format('c e'));
        $this->assertSame($original, $immutable->format('c e'));
    }

    /** @return list<array{string,bool}> */
    public static function fixedOffsets(): array
    {
        return [
            ['+00:00', false],
            ['+05:45', false],
            ['-03:30', false],
            ['+00:00', true],
            ['+05:45', true],
            ['-03:30', true],
        ];
    }

    #[DataProvider('abbreviations')]
    public function testDateFormattingKeepsTheWallTimeOfAbbreviatedTimezones(string $value, ?string $configured): void
    {
        $timezone = $configured === null ? null : new \DateTimeZone($configured);
        $formatter = new DefaultValueFormatter('en_GB', timezone: $timezone);
        $date = new \DateTimeImmutable($value);
        $expected = ($timezone === null ? $date : $date->setTimezone($timezone))->format('H:i');

        $this->assertSame($expected, $formatter->time($value));
        $this->assertSame($expected, $formatter->time($date));
    }

    /** @return list<array{string,?string}> */
    public static function abbreviations(): array
    {
        return [
            ['2026-07-01T10:00:00Z',  null],
            ['2026-07-01 10:00 BST',  null],
            ['2026-07-01 10:00 CEST', null],
            ['2026-07-01 10:00 PDT',  null],
            ['2026-07-01T10:00:00Z',  'CEST'],
            ['2026-07-01T10:00:00Z',  'Europe/Brussels'],
        ];
    }

    public function testFallbackFormattingWithoutIntl(): void
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $code =
            'if (class_exists("IntlDateFormatter") || class_exists("NumberFormatter")) { echo "HAS_INTL"; return; } '
            . "require {$autoload}; "
            . '$f = new \Kaly\Tpl\DefaultValueFormatter("en", "EUR", new \DateTimeZone("Europe/Brussels")); '
            . 'echo json_encode(['
            . '$f->date("2026-01-01T23:30:00+00:00"), '
            . '$f->time("2026-01-01T23:30:00+00:00"), '
            . '$f->datetime("2026-01-01T23:30:00+00:00"), '
            . '$f->datetime(new \DateTimeImmutable("2026-01-01T23:30:00+00:00")), '
            . '$f->money(1234.5), $f->money(1234, "JPY"), $f->number(1234.5, 2), $f->percent(0.425, 1)'
            . '], JSON_THROW_ON_ERROR);';

        $output = $this->runWithoutIntl($code);

        if ($output === 'HAS_INTL') {
            $this->markTestSkipped('ext-intl is statically loaded, cannot exercise the fallback.');
        }

        $this->assertSame(
            [
                '2026-01-02',
                '00:30',
                '2026-01-02 00:30',
                '2026-01-02 00:30',
                '1,234.50 EUR',
                '1,234.00 JPY',
                '1,234.50',
                '42.5%',
            ],
            json_decode($output, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    private function runWithoutIntl(string $code): string
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-n', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            $this->markTestSkipped('Unable to start a PHP subprocess.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame(0, $exit, 'Fallback subprocess failed: ' . $stderr);

        return $stdout;
    }
}
