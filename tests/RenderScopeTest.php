<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\ViewEngine;
use Kaly\Tpl\ViewException;
use PHPUnit\Framework\TestCase;

final class RenderScopeTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        parent::tearDown();
    }

    public function testSharedDataReachesRootIncludeLayoutAndEach(): void
    {
        $dir = $this->views([
            'index.phtml' => <<<'PHP'
                <?php $v->layout('layouts/app') ?>root:<?= $v->e($tenant) ?>|inc:<?= $v->inc('partials/card') ?>|each:<?= $v->each($items, 'partials/item', as: 'item') ?>
                PHP,
            'partials/card.phtml' => '<?= $v->e($tenant) ?>',
            'partials/item.phtml' => '<?= $v->e($item) ?>@<?= $v->e($tenant) ?>;',
            'layouts/app.phtml' => 'layout:<?= $v->e($tenant) ?>|<?= $v->content() ?>',
        ]);

        $html = (string) (new ViewEngine($dir))->render(
            'index',
            ['items' => ['a', 'b']],
            sharedData: ['tenant' => 'T'],
        );

        $this->assertSame('layout:T|root:T|inc:T|each:a@T;b@T;', $html);
    }

    public function testSharedDataReachesEachEmptyTemplate(): void
    {
        $dir = $this->views([
            'index.phtml' => '<?= $v->each($items, "partials/item", as: "item", empty: "partials/empty") ?>',
            'partials/item.phtml' => '<?= $v->e($item) ?>',
            'partials/empty.phtml' => 'empty:<?= $v->e($tenant) ?>',
        ]);

        $html = (string) (new ViewEngine($dir))->render('index', ['items' => []], sharedData: ['tenant' => 'T']);

        $this->assertSame('empty:T', $html);
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>, list<string>, string}>
     */
    public static function sharedCollisionProvider(): iterable
    {
        $layout = ['index.phtml' => 'page', 'layouts/app.phtml' => '<?= $v->content() ?>'];

        yield 'root data' => [
            ['index.phtml' => 'ok'],
            ['data' => ['url' => 'local']],
            ['url'],
            'index',
        ];
        yield 'inc data' => [
            ['index.phtml' => '<?= $v->inc("card", ["url" => "local"]) ?>', 'card.phtml' => 'card'],
            [],
            ['url'],
            'index > card',
        ];
        yield 'layout() data' => [
            [
                'index.phtml' => '<?php $v->layout("layouts/app", ["url" => "local"]) ?>page',
                'layouts/app.phtml' => '<?= $v->content() ?>',
            ],
            [],
            ['url'],
            'layouts/app',
        ];
        yield 'render() layoutData' => [
            $layout,
            [
                'layout' => 'layouts/app',
                'layoutData' => [
                    'url' => 'local',
                ],
            ],
            ['url'],
            'layouts/app',
        ];
        yield 'layout content' => [$layout, ['layout' => 'layouts/app'], ['content'], 'layouts/app'];
        yield 'each item' => [
            ['index.phtml' => '<?= $v->each([1, 2], "item", as: "row") ?>', 'item.phtml' => 'item'],
            [],
            ['row'],
            'index > item',
        ];
        yield 'each key' => [
            ['index.phtml' => '<?= $v->each([1, 2], "item") ?>', 'item.phtml' => 'item'],
            [],
            ['key'],
            'index > item',
        ];
        yield 'each loop' => [
            ['index.phtml' => '<?= $v->each([1, 2], "item") ?>', 'item.phtml' => 'item'],
            [],
            ['loop'],
            'index > item',
        ];
    }

    /**
     * @param array<string, string> $files
     * @param array<string, mixed> $args
     * @param list<string> $sharedNames
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sharedCollisionProvider')]
    public function testSharedDataCannotCollideWithTemplateLocals(
        array $files,
        array $args,
        array $sharedNames,
        string $chain,
    ): void {
        $view = new ViewEngine($this->views($files));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Template data conflicts with shared render data: "%s" (template chain: %s).',
            implode('", "', $sharedNames),
            $chain,
        ));

        $view->render('index', ...[...$args, 'sharedData' => array_fill_keys($sharedNames, 'shared')]);
    }

    public function testCollisionMessageListsEveryNameSorted(): void
    {
        $view = new ViewEngine($this->views(['index.phtml' => 'ok']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Template data conflicts with shared render data: "csrf", "url" (template chain: index).',
        );

        $view->render('index', ['url' => 1, 'csrf' => 2, 'other' => 3], sharedData: ['url' => 1, 'csrf' => 2]);
    }

    public function testSharedDataCannotCollideWithGlobals(): void
    {
        $view = new ViewEngine($this->views(['index.phtml' => 'ok']));
        $view->addGlobal('url', 'global');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Shared render data conflicts with globals: "url".');

        $view->render('index', sharedData: ['url' => 'shared']);
    }

    public function testLocalDataStillOverridesGlobals(): void
    {
        $dir = $this->views([
            'index.phtml' => '<?= $v->e($who) ?>|<?= $v->inc("card", ["who" => "inc"]) ?>',
            'card.phtml' => '<?= $v->e($who) ?>',
        ]);
        $view = new ViewEngine($dir);
        $view->addGlobal('who', 'global');

        $this->assertSame('root|inc', (string) $view->render('index', ['who' => 'root']));
    }

    public function testContentStaysAvailableAsPlainDataWithoutLayout(): void
    {
        $view = new ViewEngine($this->views(['index.phtml' => '<?= $v->e($content) ?>']));

        $this->assertSame('shared', (string) $view->render('index', sharedData: ['content' => 'shared']));
    }

    public function testNestedRenderOnTheSameEngineKeepsIndependentTemplateChains(): void
    {
        $dir = $this->views([
            'outer.phtml' => '<?= $v->inc("outer-card", ["engine" => $engine, "probe" => $probe]) ?>',
            'outer-card.phtml' => <<<'PHP'
                <?php
                try {
                    $engine->render('inner');
                } catch (\Kaly\Tpl\ViewException $innerException) {
                    $probe['innerStack'] = $innerException->templateStack();
                }
                throw new \RuntimeException('outer failed');
                PHP,
            'inner.phtml' => '<?= $v->inc("inner-card") ?>',
            'inner-card.phtml' => '<?php throw new \RuntimeException("inner failed") ?>',
        ]);
        $view = new ViewEngine($dir);
        /** @var \ArrayObject<string, mixed> $probe */
        $probe = new \ArrayObject();

        try {
            $view->render('outer', ['engine' => $view, 'probe' => $probe]);
            $this->fail('Expected the outer render to fail.');
        } catch (ViewException $exception) {
            $this->assertSame('outer failed', $exception->getPrevious()?->getMessage());
            $this->assertSame(['outer', 'outer-card'], $exception->templateStack());
        }

        $this->assertSame(['inner', 'inner-card'], $probe['innerStack'] ?? null);
    }

    public function testSharedDataCannotUseReservedNames(): void
    {
        $dir = $this->views(['index.phtml' => 'ok']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved by the view engine');

        (new ViewEngine($dir))->render('index', sharedData: ['__kalyFile' => 'bad']);
    }

    public function testRootLocalsDoNotLeakIntoIncludesOrLayouts(): void
    {
        $dir = $this->views([
            'index.phtml' => <<<'PHP'
                <?php $v->layout('layouts/app') ?><?= $v->inc('partial') ?>
                PHP,
            'partial.phtml' => '<?= isset($pageLocal) ? "LEAK" : "partial" ?>',
            'layouts/app.phtml' => '<?= isset($pageLocal) ? "LEAK" : "layout" ?>|<?= $v->content() ?>',
        ]);

        $html = (string) (new ViewEngine($dir))->render('index', ['pageLocal' => 'x']);

        $this->assertSame('layout|partial', $html);
    }

    public function testIncludeLocalDoesNotLeakIntoParent(): void
    {
        $dir = $this->views([
            'index.phtml' => '<?= $v->inc("partial", ["inner" => "1"]) ?>|<?= isset($inner) ? "LEAK" : "parent" ?>',
            'partial.phtml' => '<?= $v->e($inner) ?>',
        ]);

        $this->assertSame('1|parent', (string) (new ViewEngine($dir))->render('index'));
    }

    public function testSharedDataDoesNotLeakAcrossRenders(): void
    {
        $dir = $this->views([
            'show.phtml' => '<?= $v->e($who) ?>',
            'probe.phtml' => '<?= isset($who) ? "LEAK" : "none" ?>',
        ]);
        $view = new ViewEngine($dir);

        $this->assertSame('A', (string) $view->render('show', sharedData: ['who' => 'A']));
        $this->assertSame('none', (string) $view->render('probe'));
    }

    public function testSharedDataDoesNotSurviveAFailedRender(): void
    {
        $dir = $this->views([
            'bad.phtml' => '<?php throw new \RuntimeException("boom") ?>',
            'probe.phtml' => '<?= isset($who) ? "LEAK" : "none" ?>',
        ]);
        $view = new ViewEngine($dir);

        try {
            $view->render('bad', sharedData: ['who' => 'A']);
            $this->fail('Expected the render to fail.');
        } catch (ViewException $exception) {
            $this->assertSame('boom', $exception->getPrevious()?->getMessage());
        }

        $this->assertSame('none', (string) $view->render('probe'));
    }

    /** @param array<string, string> $files */
    private function views(array $files): string
    {
        $dir = sys_get_temp_dir() . '/kaly-tpl-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $dir;

        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            $parent = dirname($path);
            if (!is_dir($parent) && !mkdir($parent, recursive: true) && !is_dir($parent)) {
                throw new \RuntimeException(sprintf('Unable to create test directory "%s".', $parent));
            }

            if (file_put_contents($path, $contents) === false) {
                throw new \RuntimeException(sprintf('Unable to write test template "%s".', $path));
            }
        }

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                rmdir($path);
                continue;
            }

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
            unlink($path);
        }

        clearstatcache(true, $dir);
        rmdir($dir);
    }
}
