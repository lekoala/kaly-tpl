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

    public function testSharedDataOverridesHomonymousLocal(): void
    {
        $dir = $this->views(['index.phtml' => '<?= $v->e($who) ?>']);

        $html = (string) (new ViewEngine($dir))->render('index', ['who' => 'local'], sharedData: ['who' => 'shared']);

        $this->assertSame('shared', $html);
    }

    public function testSharedDataCannotOverrideLayoutContent(): void
    {
        $dir = $this->views([
            'index.phtml' => 'page',
            'layouts/app.phtml' => '<?= $content ?>|<?= $v->content() ?>',
        ]);

        $html = (string) (new ViewEngine($dir))->render('index', layout: 'layouts/app', sharedData: [
            'content' => 'SHARED',
        ]);

        $this->assertSame('page|page', $html);
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
