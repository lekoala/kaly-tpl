<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\TemplateDocblocker;
use Kaly\Tpl\ViewEngine;
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
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame($level, ob_get_level());
        $this->assertSame('good', (string) $view->render('good'));
        $this->assertSame($level, ob_get_level());
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
