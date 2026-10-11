<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\ViewEngine;
use Kaly\Tpl\ViewException;
use Kaly\Tpl\ViewLogicException;
use Kaly\Tpl\ViewUsageException;
use PHPUnit\Framework\TestCase;

final class RenderSafetyTest extends TestCase
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
