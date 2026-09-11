<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\EachOptions;
use Kaly\Tpl\Loop;
use Kaly\Tpl\LoopFrame;
use Kaly\Tpl\LoopState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EachOptionsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function reservedNames(): iterable
    {
        yield 'runtime' => ['v'];
        yield 'key metadata' => ['key'];
        yield 'loop metadata' => ['loop'];
        yield 'internal prefix' => ['__kalyItem'];
    }

    #[DataProvider('reservedNames')]
    public function testReservedItemVariableNamesAreRejected(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EachOptions(as: $name);
    }

    public function testDepthAtMaximumIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        new EachOptions(baseDepth: 2, maxDepth: 2);
    }

    public function testDepthBelowMaximumIsAllowed(): void
    {
        $options = new EachOptions(baseDepth: 1, maxDepth: 2);

        $this->assertSame(1, $options->baseDepth);
        $this->assertSame(2, $options->maxDepth);
    }

    public function testLoopNestedContinuesDepthAndPreservesTheLimit(): void
    {
        $loop = new Loop(new LoopFrame(0, null, true, null, null), null, 4, 7, new LoopState());

        $options = $loop->nested(as: 'child');

        $this->assertSame('child', $options->as);
        $this->assertSame(5, $options->baseDepth);
        $this->assertSame(7, $options->maxDepth);
    }
}
