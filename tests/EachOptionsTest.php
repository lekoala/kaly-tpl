<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\EachOptions;
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

    public function testDepthCannotExceedMaximum(): void
    {
        $this->expectException(\LogicException::class);
        new EachOptions(depth0: 3, maxDepth: 2);
    }
}
