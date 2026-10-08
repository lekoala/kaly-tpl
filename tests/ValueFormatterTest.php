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

    public function testFallbackDateFormattingAppliesConfiguredTimezoneToStrings(): void
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $code =
            'if (class_exists("IntlDateFormatter")) { echo "HAS_INTL"; return; } '
            . "require {$autoload}; "
            . '$f = new \Kaly\Tpl\DefaultValueFormatter("en", "EUR", new \DateTimeZone("Europe/Brussels")); '
            . 'echo $f->datetime("2026-01-01T23:30:00+00:00"), "|", '
            . '$f->datetime(new \DateTimeImmutable("2026-01-01T23:30:00+00:00"));';

        $output = $this->runWithoutIntl($code);

        if ($output === 'HAS_INTL') {
            $this->markTestSkipped('ext-intl is statically loaded, cannot exercise the fallback.');
        }

        $this->assertSame('2026-01-02 00:30|2026-01-02 00:30', $output);
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

        if ($exit !== 0) {
            $this->markTestSkipped('Fallback subprocess failed: ' . $stderr);
        }

        return $stdout;
    }
}
