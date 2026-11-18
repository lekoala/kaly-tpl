<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\ValueFormatter;
use Kaly\Tpl\ViewEngine;
use Kaly\Tpl\ViewException;
use PHPUnit\Framework\TestCase;

/**
 * The optional per-render formatter: one formatter for a whole render, and no
 * leak between renders of a shared engine.
 */
final class PerRenderFormatterTest extends TestCase
{
    use TemporaryViews;

    public function testASuccessionOfRendersNeverLeaksAFormatter(): void
    {
        $engine = new ViewEngine($this->views(['date.phtml' => '<?= $v->date($when) ?>']));
        $engine->formatter(new TagFormatter('engine'));

        $render = static fn(?ValueFormatter $formatter): string => trim((string) $engine->render(
            'date',
            ['when' => '2026-11-18'],
            formatter: $formatter,
        ));

        $this->assertSame('red:date', $render(new TagFormatter('red')));
        $this->assertSame('engine:date', $render(null));
        $this->assertSame('blue:date', $render(new TagFormatter('blue')));
        $this->assertSame('engine:date', $render(null));
        $this->assertSame('red:date', $render(new TagFormatter('red')));
    }

    public function testTheWholeRenderSeesOneFormatter(): void
    {
        $engine = new ViewEngine($this->views([
            'page.phtml' => <<<'PHP'
                page:<?= $v->date($when) ?>
                partial:<?= $v->inc('partial', ['when' => $when]) ?>
                each:<?= $v->each([$when], 'item') ?>
                <?php $v->layout('layout', ['when' => $when]); ?>
                PHP,
            'partial.phtml' => '<?= $v->date($when) ?>',
            'item.phtml' => '<?= $v->date($item) ?>',
            'layout.phtml' => 'layout:<?= $v->date($when) ?>|<?= $content ?>',
        ]));

        $html = (string) $engine->render('page', ['when' => '2026-11-18'], formatter: new TagFormatter('blue'));

        $this->assertSame('layout:blue:date|page:blue:datepartial:blue:dateeach:blue:date', preg_replace(
            '/\s+/',
            '',
            $html,
        ));
    }

    public function testANestedRenderResolvesItsOwnFormatterAndTheOuterOneIsUntouched(): void
    {
        $engine = new ViewEngine($this->views([
            'outer.phtml' => 'outer:<?= $v->date($when) ?>|nested:<?= $nested() ?>|after:<?= $v->date($when) ?>',
            'inner.phtml' => '<?= $v->date($when) ?>',
        ]));
        $engine->formatter(new TagFormatter('engine'));
        $engine->addGlobal(
            'nested',
            fn(): string => (string) $engine->render(
                'inner',
                ['when' => '2026-11-18'],
                formatter: new TagFormatter('blue'),
            ),
        );

        $html = (string) $engine->render('outer', ['when' => '2026-11-18'], formatter: new TagFormatter('red'));

        // The nested render uses its own formatter; the surrounding render keeps its own
        $this->assertSame(['outer:red:date', 'nested:blue:date', 'after:red:date'], explode('|', $html));
    }

    public function testANestedRenderWithoutAFormatterUsesTheEngineDefault(): void
    {
        $engine = new ViewEngine($this->views([
            'outer.phtml' => 'outer:<?= $v->date($when) ?>|nested:<?= $nested() ?>|after:<?= $v->date($when) ?>',
            'inner.phtml' => '<?= $v->date($when) ?>',
        ]));
        $engine->formatter(new TagFormatter('engine'));
        $engine->addGlobal('nested', fn(): string => (string) $engine->render('inner', ['when' => '2026-11-18']));

        $html = (string) $engine->render('outer', ['when' => '2026-11-18'], formatter: new TagFormatter('red'));

        // The outer render's formatter is per-render state, never ambient for another render
        $this->assertSame(['outer:red:date', 'nested:engine:date', 'after:red:date'], explode('|', $html));
    }

    public function testAFailedRenderLeavesTheEngineFormatterUnchanged(): void
    {
        $engine = new ViewEngine($this->views([
            'throwing.phtml' => '<?= $v->date($when) ?><?php throw new \DomainException("no"); ?>',
            'date.phtml' => '<?= $v->date($when) ?>',
        ]));
        $engine->formatter(new TagFormatter('engine'));

        try {
            $engine->render('throwing', ['when' => '2026-11-18'], formatter: new TagFormatter('red'));
            $this->fail('Expected a render failure');
        } catch (ViewException $exception) {
            $this->assertInstanceOf(\DomainException::class, $exception->getPrevious());
        }

        $this->assertSame('engine:date', trim((string) $engine->render('date', ['when' => '2026-11-18'])));
    }
}

/** A formatter whose every value names its tag, so a test can tell formatters apart. */
final class TagFormatter implements ValueFormatter
{
    public function __construct(
        private readonly string $tag,
    ) {}

    public function date(\DateTimeInterface|string|null $value, string $style = 'medium'): string
    {
        return $this->tag . ':date';
    }

    public function time(\DateTimeInterface|string|null $value, string $style = 'short'): string
    {
        return $this->tag . ':time';
    }

    public function datetime(\DateTimeInterface|string|null $value, string $style = 'medium'): string
    {
        return $this->tag . ':datetime';
    }

    public function money(int|float|string|null $amount, ?string $currency = null): string
    {
        return $this->tag . ':money';
    }

    public function number(int|float|string|null $value, int $decimals = 0): string
    {
        return $this->tag . ':number';
    }

    public function percent(int|float|string|null $value, int $decimals = 0): string
    {
        return $this->tag . ':percent';
    }

    public function duration(\DateInterval|int|float|string|null $value, string $style = 'short'): string
    {
        return $this->tag . ':duration';
    }
}
