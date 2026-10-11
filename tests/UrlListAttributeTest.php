<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\AttributeEscaper;
use Kaly\Tpl\DefaultEscaper;
use Kaly\Tpl\Escaper;
use Kaly\Tpl\Html;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlListAttributeTest extends TestCase
{
    public function testSrcsetKeepsCommasInsideUrlsAndSeveralCandidates(): void
    {
        $escaper = new DefaultEscaper();

        $this->assertSame(
            ' srcset="/photo,large.jpg 2x, /photo.jpg 1x"',
            (string) $escaper->attrs(['srcset' => '/photo,large.jpg 2x, /photo.jpg 1x']),
        );
        $this->assertSame(
            ' srcset="/a,b.jpg, /c.jpg 2x"',
            (string) $escaper->attrs(['srcset' => " /a,b.jpg,\n/c.jpg   2x "]),
        );
        $this->assertSame(
            ' imagesrcset="/s.jpg 480w, /m.jpg 800w"',
            (string) $escaper->attrs(['imagesrcset' => '/s.jpg 480w,/m.jpg 800w']),
        );
        $this->assertSame(
            ' srcset="/img?a=1&amp;b=2 1.5x"',
            (string) $escaper->attrs(['srcset' => '/img?a=1&b=2 1.5x']),
        );
    }

    public function testRefusedCandidatesAreDropped(): void
    {
        $this->assertSame(
            ' srcset="/ok.jpg 2x"',
            (string) (new DefaultEscaper())->attrs(['srcset' => 'javascript:alert(1) 1x, /ok.jpg 2x']),
        );
    }

    public function testAttributeIsOmittedWhenNoCandidateIsLeft(): void
    {
        $escaper = new DefaultEscaper();

        $this->assertSame(' alt="a"', (string) $escaper->attrs(['srcset' => 'javascript:alert(1)', 'alt' => 'a']));
        $this->assertSame(' alt="a"', (string) $escaper->attrs(['srcset' => '//evil.test/x.jpg 2x', 'alt' => 'a']));
        $this->assertSame(' alt="a"', (string) $escaper->attrs(['srcset' => '  ', 'alt' => 'a']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSrcsets(): iterable
    {
        yield 'leading comma' => [', /a.jpg', 'empty image candidate'];
        yield 'double comma' => ['/a.jpg,, /b.jpg 2x', 'empty image candidate'];
        yield 'trailing comma' => ['/a.jpg 1x,', 'empty image candidate'];
        yield 'two descriptors' => ['/a.jpg 1x 2x', 'at most one descriptor'];
        yield 'unknown descriptor' => ['/a.jpg 2q', 'unknown descriptor "2q"'];
        yield 'zero density' => ['/a.jpg 0x', 'unknown descriptor "0x"'];
        yield 'implicit 1x duplicate' => ['/a.jpg, /b.jpg 1x', 'duplicate descriptor "1x"'];
        yield 'equivalent densities' => ['/a.jpg 1x, /b.jpg 1.0x', 'duplicate descriptor "1.0x"'];
        yield 'mixed descriptors' => ['/a.jpg 100w, /b.jpg 2x', 'cannot be mixed'];
    }

    #[DataProvider('invalidSrcsets')]
    public function testInvalidSrcsetSyntaxIsRejected(string $srcset, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        (new DefaultEscaper())->attrs(['srcset' => $srcset]);
    }

    public function testInvalidSyntaxIsRejectedEvenWhenTheUrlsWouldBeDropped(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DefaultEscaper())->attrs(['srcset' => 'javascript:a 1x, javascript:b 1x']);
    }

    public function testPingKeepsHttpUrlsAndDropsOthers(): void
    {
        $escaper = new DefaultEscaper();

        $this->assertSame(
            ' ping="/track https://stats.test/p?a=1&amp;b=2"',
            (string) $escaper->attrs([
                'ping' => " /track\thttps://stats.test/p?a=1&b=2 javascript:alert(1) mailto:a@b.test\n",
            ]),
        );
        $this->assertSame(' href="/"', (string) $escaper->attrs(['href' => '/', 'ping' => 'javascript:x tel:123']));
    }

    public function testCustomEscaperKeepsControlOfFinalUrlEscaping(): void
    {
        $attributes = new AttributeEscaper(new class implements Escaper {
            private DefaultEscaper $default;

            public function __construct()
            {
                $this->default = new DefaultEscaper();
            }

            public function html(mixed $value): string
            {
                return $this->default->html($value);
            }

            public function attr(mixed $value): string
            {
                return $this->default->attr($value);
            }

            public function url(string|\Stringable|null $value): string
            {
                return $this->default->url($value) . '?v=1';
            }

            public function json(mixed $value): Html
            {
                return $this->default->json($value);
            }

            public function attrs(array $attributes): Html
            {
                return $this->default->attrs($attributes);
            }

            public function classes(mixed ...$values): string
            {
                return $this->default->classes(...$values);
            }

            public function styleVars(array $vars): Html
            {
                return $this->default->styleVars($vars);
            }
        });

        $this->assertSame(
            ' srcset="/a.jpg?v=1 1x, /b.jpg?v=1 2x" ping="/t?v=1"',
            (string) $attributes->attrs(['srcset' => '/a.jpg 1x, /b.jpg 2x', 'ping' => '/t']),
        );
    }
}
