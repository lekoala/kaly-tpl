<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\DocblockTypeMerger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocblockTypeMergerTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function types(): iterable
    {
        yield 'nullable value' => ['?string', 'string', '?string'];
        yield 'nullable null' => ['?string', 'null', '?string'];
        yield 'nullable widening' => ['?string', 'int', '?string|int'];
        yield 'scalar integer' => ['scalar', 'int', 'scalar'];
        yield 'scalar string' => ['scalar', 'string', 'scalar'];
        yield 'scalar float' => ['scalar', 'float', 'scalar'];
        yield 'scalar boolean' => ['scalar', 'bool', 'scalar'];
        yield 'numeric integer' => ['numeric', 'int', 'numeric'];
        yield 'numeric float' => ['numeric', 'float', 'numeric'];
        yield 'numeric string may not be numeric' => ['numeric', 'string', 'numeric|string'];
        yield 'array key integer' => ['array-key', 'int', 'array-key'];
        yield 'array key string' => ['array-key', 'string', 'array-key'];
        yield 'object class' => ['object', '\\stdClass', 'object'];
        yield 'nullable object' => ['?object', '\\stdClass', '?object'];
        yield 'parenthesized intersection' => ['(\\Foo&\\Bar)|null', '\\Foo', '(\\Foo&\\Bar)|null'];
        yield 'parenthesized generic' => ['(list<string>)', 'array', '(list<string>)'];
        yield 'parenthesized union' => ['(string|int)', 'int', '(string|int)'];
        yield 'distinct parenthesized members' => ['(\\Foo)&(\\Bar)', '\\Bar', '(\\Foo)&(\\Bar)'];
        yield 'unrelated class' => ['(\\Foo&\\Bar)|null', '\\Baz', '(\\Foo&\\Bar)|null|\\Baz'];
        yield 'short names are ambiguous' => ['User', '\\App\\User', 'User|\\App\\User'];
        yield 'supertype prefix is not a supertype' => ['scalarish', 'int', 'scalarish|int'];
    }

    #[DataProvider('types')]
    public function testWrittenTypesCoverOnlyCompatibleObservations(
        string $written,
        string $observed,
        string $expected,
    ): void {
        $merger = new DocblockTypeMerger();
        $merged = $merger->merge($written, $observed);

        $this->assertSame($expected, $merged);
        $this->assertSame($expected, $merger->merge($merged, $observed));
    }
}
