<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\TemplateDocblocker;
use Kaly\Tpl\ViewEngine;
use Kaly\Tpl\ViewException;
use PHPUnit\Framework\TestCase;

final class ViewEngineTest extends TestCase
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

    public function testItRendersLayoutAndEscapesValues(): void
    {
        $dir = $this->views([
            'index.phtml' => <<<'PHP'
                <?php $v->title('Patients <unsafe>') ?>
                <h1><?= $v->e($name) ?></h1>
                PHP,
            'layouts/app.phtml' => <<<'PHP'
                <title><?= $v->e($v->title()) ?></title>
                <main><?= $v->content() ?></main>
                PHP,
        ]);

        $html = (string) (new ViewEngine($dir))->render(
            'index',
            ['name' => 'Joséphine <script>'],
            layout: 'layouts/app',
        );

        $this->assertStringContainsString('<title>Patients &lt;unsafe&gt;</title>', $html);
        $this->assertStringContainsString('<h1>Joséphine &lt;script&gt;</h1>', $html);
    }

    public function testTemplateCanPassExplicitDataToItsLayout(): void
    {
        $dir = $this->views([
            'index.phtml' => <<<'PHP'
                <?php $v->layout('layouts/app', ['currentUser' => $user]) ?>
                content
                PHP,
            'layouts/app.phtml' => '<?= $v->e($currentUser["name"]) ?>|<?= $v->content() ?>',
        ]);

        $html = (string) (new ViewEngine($dir))->render('index', ['user' => ['name' => 'Ada']]);

        $this->assertSame('Ada|content', preg_replace('/\s+/', '', $html));
    }

    public function testCallerCanPassDataToTheLayout(): void
    {
        $dir = $this->views([
            'index.phtml' => 'page',
            'layouts/app.phtml' => '<?= $v->e($label) ?>|<?= $v->content() ?>',
        ]);

        $html = (string) (new ViewEngine($dir))->render('index', layout: 'layouts/app', layoutData: [
            'label' => 'Title',
        ]);

        $this->assertSame('Title|page', $html);
    }

    public function testLayoutDataCannotOverrideContent(): void
    {
        $dir = $this->views([
            'index.phtml' => 'page',
            'layouts/app.phtml' => '<?= $content ?>',
        ]);

        $html = (string) (new ViewEngine($dir))->render('index', layout: 'layouts/app', layoutData: [
            'content' => 'bad',
        ]);

        $this->assertSame('page', $html);
    }

    public function testEachSupportsGeneratorsAndLookaheadLoopMetadata(): void
    {
        $dir = $this->views([
            'index.phtml' => <<<'PHP'
                <?= $v->each($items, 'item', as: 'item') ?>
                PHP,
            'item.phtml' => <<<'PHP'
                <?= $v->e($item) ?>:<?= $loop->last() ? 'last' : 'more' ?>:<?= $v->e($loop->cycle('odd', 'even')) ?>;
                PHP,
        ]);

        $items = (static function (): \Generator {
            yield 'a';
            yield 'b';
            yield 'c';
        })();

        $html = (string) (new ViewEngine($dir))->render('index', ['items' => $items]);

        $this->assertSame('a:more:odd;b:more:even;c:last:odd;', trim($html));
    }

    public function testNestedLoopsTrackDepth(): void
    {
        $dir = $this->views([
            'index.phtml' => '<?= $v->each($menu, "menu/item", as: "item") ?>',
            'menu/item.phtml' => <<<'PHP'
                <?= $v->e($item['label']) ?>:<?= $loop->depth() ?>;
                <?= ($item['children'] ?? []) === [] ? '' : $v->each($item['children'], 'menu/item', $loop->nested(as: 'item')) ?>
                PHP,
        ]);

        $menu = [
            [
                'label' => 'a',
                'children' => [
                    ['label' => 'a1', 'children' => [['label' => 'a1x']]],
                ],
            ],
        ];

        $html = (string) (new ViewEngine($dir))->render('index', ['menu' => $menu]);

        $this->assertSame('a:1;a1:2;a1x:3;', preg_replace('/\s+/', '', $html));
    }

    public function testNestedLoopPreservesCustomRecursionLimit(): void
    {
        $dir = $this->views([
            'index.phtml' => '<?= $v->each($menu, "menu/item", \Kaly\Tpl\EachOptions::as("item")->maxDepth(2)) ?>',
            'menu/item.phtml' => <<<'PHP'
                <?= $v->e($item['label']) ?>
                <?= ($item['children'] ?? []) === [] ? '' : $v->each($item['children'], 'menu/item', $loop->nested(as: 'item')) ?>
                PHP,
        ]);

        $menu = [
            [
                'label' => 'a',
                'children' => [
                    ['label' => 'a1', 'children' => [['label' => 'a1x']]],
                ],
            ],
        ];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Maximum loop depth of 2 exceeded.');

        (new ViewEngine($dir))->render('index', ['menu' => $menu]);
    }

    public function testItSupportsNamespacesAndTemplateExistenceChecks(): void
    {
        $root = $this->views(['index.phtml' => 'root']);
        $admin = $this->views(['users/index.phtml' => 'admin']);
        $view = (new ViewEngine($root))->addPath('admin', $admin);

        $this->assertTrue($view->exists('index'));
        $this->assertTrue($view->exists('@admin/users/index'));
        $this->assertTrue($view->exists('admin::users/index'));
        $this->assertFalse($view->exists('@admin/users/missing'));
        $this->assertFalse($view->exists('@missing/users/index'));
        $this->assertSame('admin', (string) $view->render('@admin/users/index'));
    }

    public function testTemplateExceptionDoesNotLeakOutputBuffers(): void
    {
        $dir = $this->views([
            'bad.phtml' => '<?php echo "before"; throw new \\RuntimeException("boom");',
            'good.phtml' => 'good',
        ]);
        $view = new ViewEngine($dir);
        $level = ob_get_level();

        try {
            $view->render('bad');
            $this->fail('Expected the template exception to be rethrown.');
        } catch (ViewException $exception) {
            $this->assertSame('boom', $exception->getPrevious()?->getMessage());
            $this->assertSame(['bad'], $exception->templateStack());
            $this->assertStringContainsString('template chain: bad', $exception->getMessage());
        }

        $this->assertSame($level, ob_get_level());
        $this->assertSame('good', (string) $view->render('good'));
        $this->assertSame($level, ob_get_level());
    }

    public function testNestedIncludeFailuresExposeTheTemplateChain(): void
    {
        $dir = $this->views([
            'users/index.phtml' => "<?= \$v->inc('users/card') ?>",
            'users/card.phtml' => "<?= \$v->inc('partials/avatar') ?>",
            'partials/avatar.phtml' => '<?php throw new \RuntimeException("avatar failed") ?>',
        ]);

        try {
            (new ViewEngine($dir))->render('users/index');
            $this->fail('Expected the include failure to be rethrown.');
        } catch (ViewException $exception) {
            $this->assertSame('avatar failed', $exception->getPrevious()?->getMessage());
            $this->assertSame(['users/index', 'users/card', 'partials/avatar'], $exception->templateStack());
            $this->assertStringContainsString('users/index > users/card > partials/avatar', $exception->getMessage());
        }
    }

    public function testCaughtPartialFailureDoesNotLeakIntoALaterErrorChain(): void
    {
        $dir = $this->views([
            'index.phtml' => <<<'PHP'
                <?php try { $v->inc('recoverable'); } catch (\RuntimeException) {} ?>
                <?php $v->inc('failing') ?>
                PHP,
            'recoverable.phtml' => '<?php throw new \RuntimeException("recoverable") ?>',
            'failing.phtml' => '<?php throw new \RuntimeException("fatal") ?>',
        ]);

        try {
            (new ViewEngine($dir))->render('index');
            $this->fail('Expected the render to fail.');
        } catch (ViewException $exception) {
            $this->assertSame('fatal', $exception->getPrevious()?->getMessage());
            $this->assertSame(['index', 'failing'], $exception->templateStack());
        }
    }

    public function testEngineCanRenderRepeatedlyAndAfterFailures(): void
    {
        $dir = $this->views([
            'index.phtml' => '<?= $v->e($name) ?>',
            'bad.phtml' => '<?php throw new \RuntimeException("boom") ?>',
        ]);
        $view = (new ViewEngine($dir))->addGlobal('appName', 'App');

        $this->assertSame('a', (string) $view->render('index', ['name' => 'a']));
        $this->assertSame('b', (string) $view->render('index', ['name' => 'b']));

        try {
            $view->render('bad');
            $this->fail('Expected the failing template to throw.');
        } catch (ViewException $exception) {
            $this->assertSame('boom', $exception->getPrevious()?->getMessage());
        }

        $this->assertSame('c', (string) $view->render('index', ['name' => 'c']));
    }

    public function testUnclosedCaptureFailsWithoutLeakingOutputBuffers(): void
    {
        $dir = $this->views([
            'bad.phtml' => '<?php $v->start("body") ?>unfinished',
            'good.phtml' => 'good',
        ]);
        $view = new ViewEngine($dir);
        $level = ob_get_level();

        try {
            $view->render('bad');
            $this->fail('Expected an unclosed capture exception.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('Unclosed view capture', $exception->getMessage());
        }

        $this->assertSame($level, ob_get_level());
        $this->assertSame('good', (string) $view->render('good'));
    }

    public function testPartialCannotCloseCaptureOpenedByParentTemplate(): void
    {
        $dir = $this->views([
            'index.phtml' => <<<'PHP'
                <?php $v->start('body') ?>
                parent-before
                <?php try { echo $v->inc('bad'); } catch (\LogicException) {} ?>
                parent-after
                <?php $v->end() ?>
                <?= $v->block('body') ?>
                PHP,
            'bad.phtml' => '<?php $v->end() ?>',
        ]);
        $view = new ViewEngine($dir);
        $level = ob_get_level();

        $html = preg_replace('/\s+/', '', (string) $view->render('index'));

        $this->assertSame('parent-beforeparent-after', $html);
        $this->assertSame($level, ob_get_level());
    }

    public function testCaughtPartialFailureRollsBackViewState(): void
    {
        $dir = $this->views([
            'index.phtml' => <<<'PHP'
                <?php $v->title('before') ?>
                <?php try { echo $v->inc('bad'); } catch (\RuntimeException) {} ?>
                <?= $v->e($v->title()) ?>|<?= $v->stack('head') ?>done
                PHP,
            'bad.phtml' => <<<'PHP'
                <?php $v->title('bad') ?>
                <?php $v->push('head') ?>leaked
                <?php throw new \RuntimeException('boom') ?>
                PHP,
        ]);

        $html = preg_replace('/\s+/', '', (string) (new ViewEngine($dir))->render('index'));

        $this->assertSame('before|done', $html);
    }

    public function testCircularLayoutsAreRejected(): void
    {
        $dir = $this->views([
            'index.phtml' => '<?php $v->layout("layout-a") ?>content',
            'layout-a.phtml' => '<?php $v->layout("layout-b") ?><?= $v->content() ?>',
            'layout-b.phtml' => '<?php $v->layout("layout-a") ?><?= $v->content() ?>',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Circular layout chain');

        (new ViewEngine($dir))->render('index');
    }

    public function testInternalTemplateVariablesAreReserved(): void
    {
        $dir = $this->views(['index.phtml' => 'ok']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved by the view engine');

        (new ViewEngine($dir))->render('index', ['__kalyFile' => 'bad']);
    }

    public function testTemplateNamesOnlyRejectActualParentSegments(): void
    {
        $dir = $this->views(['foo..bar.phtml' => 'ok']);
        $view = new ViewEngine($dir);

        $this->assertTrue($view->exists('foo..bar'));
        $this->assertTrue($view->exists('foo..bar.phtml'));
        $this->assertFalse($view->exists('../foo'));
        $this->assertFalse($view->exists('nested/../foo'));
    }

    public function testTemplateDocblockerPrependsManagedDocblock(): void
    {
        $dir = $this->views([
            'index.phtml' => '<h1><?= $v->e($title) ?></h1>',
        ]);

        $file = $dir . '/index.phtml';
        $changed = TemplateDocblocker::syncManaged()->update($file, ['title' => 'Hello']);

        $this->assertTrue($changed);
        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString('@kaly-template', $contents);
        $this->assertStringContainsString('@var string $title', $contents);
    }

    public function testTemplateDocblockerKeepsStrictTypesTemplatesExecutable(): void
    {
        $dir = $this->views([
            'index.phtml' => "<?php\n\ndeclare(strict_types=1);\n\necho \$v->e(\$title);\n",
        ]);
        $file = $dir . '/index.phtml';

        TemplateDocblocker::syncManaged()->update($file, ['title' => 'Hello']);

        $contents = (string) file_get_contents($file);
        $open = strpos($contents, '<?php');
        $declare = strpos($contents, 'declare(strict_types=1)');

        $this->assertNotFalse($open);
        $this->assertNotFalse($declare);

        $beforeDeclare = substr($contents, (int) $open, (int) $declare - (int) $open);
        $this->assertStringContainsString('@kaly-template', $beforeDeclare);
        $this->assertStringNotContainsString('?>', $beforeDeclare);

        $this->assertSame('Hello', (string) (new ViewEngine($dir))->render('index', ['title' => 'Hello']));
    }

    public function testTemplateDocblockerPreservesLeadingContentWhitespace(): void
    {
        $dir = $this->views(['index.phtml' => '  hello']);
        $file = $dir . '/index.phtml';
        $blocker = TemplateDocblocker::syncManaged();

        $blocker->update($file, ['name' => 'x']);
        $blocker->update($file, ['name' => 'x']);

        $this->assertStringEndsWith('  hello', (string) file_get_contents($file));
    }

    public function testTemplateDocblockerPreservesContentOnHeaderLine(): void
    {
        $dir = $this->views([
            'index.phtml' => "<?php\n\n/**\n * @kaly-template\n * @var \\Kaly\\Tpl\\HtmlView \$v\n */\n?>  hello",
        ]);
        $file = $dir . '/index.phtml';

        TemplateDocblocker::syncManaged()->update($file, []);

        $this->assertStringEndsWith('  hello', (string) file_get_contents($file));
    }

    public function testTemplateDocblockerUnionsObservedTypes(): void
    {
        $dir = $this->views(['index.phtml' => '<?php echo "";']);
        $file = $dir . '/index.phtml';
        $blocker = TemplateDocblocker::syncManaged();

        $blocker->update($file, ['item' => null]);
        $blocker->update($file, ['item' => new \stdClass(), 'count' => 1]);
        $blocker->update($file, ['item' => new \stdClass(), 'count' => '1']);

        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString('@var null|\stdClass $item', $contents);
        $this->assertStringContainsString('@var int|string $count', $contents);
    }

    public function testTemplateDocblockerKeepsManualDocblocksIntact(): void
    {
        $dir = $this->views([
            'index.phtml' => "<?php\n\n/** @var \\stdClass \$user */\n",
        ]);
        $file = $dir . '/index.phtml';

        TemplateDocblocker::syncManaged()->update($file, ['user' => new \stdClass()]);

        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString('@kaly-template', $contents);
        $this->assertStringContainsString('/** @var \stdClass $user */', $contents);
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
