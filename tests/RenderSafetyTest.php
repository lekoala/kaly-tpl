<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\DefaultValueFormatter;
use Kaly\Tpl\ViewEngine;
use Kaly\Tpl\ViewException;
use Kaly\Tpl\ViewLogicException;
use Kaly\Tpl\ViewUsageException;
use PHPUnit\Framework\TestCase;

final class RenderSafetyTest extends TestCase
{
    use TemporaryViews;

    public function testRecursiveIncludesAreRejected(): void
    {
        $dir = $this->views([
            'index.phtml' => "<?= \$v->inc('tree') ?>",
            'tree.phtml' => "<?= \$v->inc('tree') ?>",
        ]);
        $level = ob_get_level();

        try {
            (new ViewEngine($dir))->render('index');
            $this->fail('Expected the recursive include to be rejected.');
        } catch (ViewLogicException $exception) {
            $this->assertStringContainsString(
                'Maximum template depth of 256 exceeded at "tree"',
                $exception->getMessage(),
            );
            $this->assertStringContainsString('tree > tree', $exception->getMessage());
        }

        $this->assertSame($level, ob_get_level());
    }

    public function testApplicationExceptionsAreWrappedWhateverTheirClass(): void
    {
        $dir = $this->views([
            'index.phtml' => "<?= \$v->inc('card') ?>",
            'card.phtml' => '<?php throw new \DomainException("invalid state") ?>',
        ]);

        try {
            (new ViewEngine($dir))->render('index');
            $this->fail('Expected the application exception to be wrapped.');
        } catch (ViewException $exception) {
            $this->assertInstanceOf(\DomainException::class, $exception->getPrevious());
            $this->assertSame(['index', 'card'], $exception->templateStack());
        }
    }

    public function testEngineUsageErrorsAreNotWrapped(): void
    {
        $dir = $this->views(['index.phtml' => '<?php $v->start("1invalid") ?>']);

        $this->expectException(ViewUsageException::class);
        $this->expectExceptionMessage('Invalid view section name "1invalid".');

        (new ViewEngine($dir))->render('index');
    }

    public function testBlockDefaultIsEscapedUnlessHtml(): void
    {
        $dir = $this->views([
            'index.phtml' => <<<'PHP'
                <?= $v->block('a', '<b>text</b>') ?>|<?= $v->block('b', $v->html('<b>html</b>')) ?>
                PHP,
        ]);

        $html = (string) (new ViewEngine($dir))->render('index');

        $this->assertSame('&lt;b&gt;text&lt;/b&gt;|<b>html</b>', $html);
    }

    public function testContentBlockCannotBeCaptured(): void
    {
        $dir = $this->views(['index.phtml' => '<?php $v->start("content") ?>x<?php $v->end() ?>']);

        $this->expectException(ViewUsageException::class);
        $this->expectExceptionMessage('The "content" block is managed by the engine');

        (new ViewEngine($dir))->render('index');
    }

    public function testContentStillFlowsThroughNestedLayouts(): void
    {
        $dir = $this->views([
            'index.phtml' => '<?php $v->layout("layouts/inner") ?>page',
            'layouts/inner.phtml' => '<?php $v->layout("layouts/outer") ?>[<?= $v->content() ?>]',
            'layouts/outer.phtml' => '<main><?= $v->content() ?>|<?= $v->block("content") ?></main>',
        ]);

        $this->assertSame('<main>[page]|[page]</main>', (string) (new ViewEngine($dir))->render('index'));
    }

    public function testClonedEngineDoesNotShareViewPaths(): void
    {
        $view = new ViewEngine($this->views(['index.phtml' => 'index']));
        $copy = clone $view;

        $copy->addPath('theme', $this->views(['card.phtml' => 'card']));

        $this->assertTrue($copy->exists('@theme/card'));
        $this->assertFalse($view->exists('@theme/card'));
    }

    public function testResolvedTemplatesAreCachedButMissingOnesAreNot(): void
    {
        $dir = $this->views(['index.phtml' => 'index']);
        $view = new ViewEngine($dir);

        $this->assertFalse($view->exists('late'));
        file_put_contents($dir . '/late.phtml', 'late');
        $this->assertSame('late', (string) $view->render('late'));
    }

    public function testAddPathInvalidatesResolvedTemplates(): void
    {
        $first = $this->views(['card.phtml' => 'first']);
        $second = $this->views(['card.phtml' => 'second']);
        $view = (new ViewEngine($first))->addPath('ui', $first);

        $this->assertSame('first', (string) $view->render('@ui/card'));
        $this->assertSame('second', (string) $view->addPath('ui', $second)->render('@ui/card'));
    }

    public function testDebugModeResolvesTemplatesOnEveryRender(): void
    {
        $dir = $this->views(['index.phtml' => 'index']);
        $view = (new ViewEngine($dir))->debug();

        $this->assertSame('index', (string) $view->render('index'));
        unlink($dir . '/index.phtml');

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('View "index" not found');

        $view->render('index');
    }

    public function testLocaleCanBeCalledRepeatedlyAndBeforeACustomFormatter(): void
    {
        $dir = $this->views(['index.phtml' => '<?= $v->number(1234.56, 2) ?>']);
        $custom = new DefaultValueFormatter('fr_BE');

        $view = (new ViewEngine($dir))
            ->locale('de_DE')
            ->locale('en');
        $this->assertSame('1,234.56', (string) $view->render('index'));

        $view->formatter($custom);
        $this->assertSame($custom->number(1234.56, 2), (string) $view->render('index'));
    }

    public function testLocaleCannotReplaceACustomFormatter(): void
    {
        $view = (new ViewEngine($this->views(['index.phtml' => ''])))->formatter(new DefaultValueFormatter());

        $this->expectException(ViewLogicException::class);
        $this->expectExceptionMessage('locale() would replace the custom formatter');

        $view->locale('fr_BE');
    }
}
