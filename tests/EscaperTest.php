<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\DefaultEscaper;
use PHPUnit\Framework\TestCase;

final class EscaperTest extends TestCase
{
    public function testHtmlEscapingAcceptsNullAndRejectsNonStringableObjects(): void
    {
        $escaper = new DefaultEscaper();

        $this->assertSame('', $escaper->html(null));
        $this->assertSame('&lt;strong&gt;', $escaper->html('<strong>'));

        $this->expectException(\InvalidArgumentException::class);
        $escaper->html(new \stdClass());
    }

    public function testUrlEscapingOnlyAllowsExpectedSchemesAndRejectsControlCharacters(): void
    {
        $escaper = new DefaultEscaper();

        $this->assertSame('#', $escaper->url('javascript:alert(1)'));
        $this->assertSame('#', $escaper->url("java\nscript:alert(1)"));
        $this->assertSame('#', $escaper->url("java\tscript:alert(1)"));
        $this->assertSame('#', $escaper->url('//example.test/path'));
        $this->assertSame('#', $escaper->url('\\\\example.test/path'));
        $this->assertSame('/patients?status=pending&amp;page=2', $escaper->url('/patients?status=pending&page=2'));
        $this->assertSame('https://example.test/a?b=1&amp;c=2', $escaper->url('https://example.test/a?b=1&c=2'));
    }

    public function testAttributeBagDistinguishesHtmlBooleanAndAriaBooleanValues(): void
    {
        $escaper = new DefaultEscaper();

        $attributes = (string) $escaper->attrs([
            'disabled' => true,
            'required' => false,
            'aria-expanded' => false,
            'data-active' => true,
        ]);

        $this->assertSame(' disabled aria-expanded="false" data-active="true"', $attributes);
    }


    public function testAttributeBagUsesUrlPolicyAndRejectsDedicatedScriptContexts(): void
    {
        $escaper = new DefaultEscaper();

        $this->assertSame(' href="#"', (string) $escaper->attrs(['href' => 'javascript:alert(1)']));
        $this->assertSame(' href="/patients?a=1&amp;b=2"', (string) $escaper->attrs(['href' => '/patients?a=1&b=2']));

        $this->expectException(\InvalidArgumentException::class);
        $escaper->attrs(['onclick' => 'alert(1)']);
    }

    public function testClassAndStyleHelpersAreSafeWhenEchoedInsideAttributes(): void
    {
        $escaper = new DefaultEscaper();

        $this->assertSame('card x&quot;', $escaper->classes('card', 'x"'));
        $this->assertSame('--label: &quot;x&quot;', (string) $escaper->styleVars(['--label' => '"x"']));
        $this->assertSame(
            ' class="card x&quot;" style="--label: &quot;x&quot;"',
            (string) $escaper->attrs(['class' => ['card', 'x"'], 'style' => ['--label' => '"x"']]),
        );
    }

    public function testAttributeListsPreserveZeroAndRejectNestedValues(): void
    {
        $escaper = new DefaultEscaper();

        $this->assertSame(
            ' aria-describedby="first 0 last"',
            (string) $escaper->attrs(['aria-describedby' => ['first', 0, 'last']]),
        );

        $this->expectException(\InvalidArgumentException::class);
        $escaper->attrs(['aria-describedby' => [['nested']]]);
    }

    public function testUnsafeStyleVariableSeparatorsAreRejected(): void
    {
        $escaper = new DefaultEscaper();

        $this->expectException(\InvalidArgumentException::class);
        $escaper->styleVars(['--color' => 'red; background: url(x)']);
    }
}
