<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\DefaultValueFormatter;
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
}
