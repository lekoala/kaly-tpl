<?php

declare(strict_types=1);

namespace Kaly\Tpl\Tests;

use Kaly\Tpl\TemplateDocblocker;
use Kaly\Tpl\ViewEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateDocblockerTest extends TestCase
{
    use TemporaryViews;

    public function testGenericUnionsSurviveRepeatedRenders(): void
    {
        $file = $this->managed(' * @var array<string, int|string> $counts');
        $blocker = TemplateDocblocker::syncManaged();

        $blocker->update($file, ['counts' => ['a' => 1]]);
        $blocker->update($file, ['counts' => ['b' => 'x']]);

        $this->assertStringContainsString(
            " * @var array<string, int|string> \$counts\n",
            (string) file_get_contents($file),
        );
    }

    public function testInferredTypesNeverDegradeMorePreciseWrittenTypes(): void
    {
        $file = $this->managed(implode("\n", [
            ' * @var array<string, int> $counts',
            ' * @var list<\stdClass> $users',
            ' * @var non-empty-string $title',
            ' * @var iterable<int, string> $rows',
            ' * @var string[] $tags',
            ' * @var iterable<int, string> $items',
            ' * @var iterable $any',
        ]));

        TemplateDocblocker::syncManaged()->update($file, [
            'counts' => ['a' => 1],
            'users' => [new \stdClass()],
            'title' => 'Hello',
            'rows' => new \ArrayIterator(['x']),
            'tags' => ['a'],
            'items' => ['a'],
            'any' => ['a'],
        ]);

        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString(" * @var array<string, int> \$counts\n", $contents);
        $this->assertStringContainsString(" * @var list<\\stdClass> \$users\n", $contents);
        $this->assertStringContainsString(" * @var non-empty-string \$title\n", $contents);
        $this->assertStringContainsString(" * @var iterable<int, string> \$rows\n", $contents);
        $this->assertStringContainsString(" * @var string[] \$tags\n", $contents);
        $this->assertStringContainsString(" * @var iterable<int, string> \$items\n", $contents);
        $this->assertStringContainsString(" * @var iterable \$any\n", $contents);
    }

    public function testNewObservedTypesStillWidenWrittenTypes(): void
    {
        $file = $this->managed(' * @var list<\stdClass> $users');

        TemplateDocblocker::syncManaged()->update($file, ['users' => null]);

        $this->assertStringContainsString(" * @var list<\\stdClass>|null \$users\n", (string) file_get_contents($file));
    }

    public function testUnparsedLinesAndDescriptionsAreKept(): void
    {
        $file = $this->managed(implode("\n", [
            ' * @var \stdClass $user the signed-in user',
            ' * @phpstan-var array{id: int} $row',
            ' * Rendered by the account controller.',
        ]));

        TemplateDocblocker::syncManaged()->update($file, ['user' => new \stdClass()]);

        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString(" * @var \\stdClass \$user the signed-in user\n", $contents);
        $this->assertStringContainsString(" * @phpstan-var array{id: int} \$row\n", $contents);
        $this->assertStringContainsString(" * Rendered by the account controller.\n", $contents);
    }

    public function testManualDocblocksWithoutMarkerAreLeftUntouched(): void
    {
        $original = "<?php\n\n/**\n * @var \\Kaly\\Tpl\\HtmlView \$v\n * @var array<string, int> \$counts\n */\n?>\nbody";
        $file = $this->views(['index.phtml' => $original]) . '/index.phtml';

        $changed = TemplateDocblocker::syncManaged()->update($file, ['counts' => [], 'extra' => 1]);

        $this->assertFalse($changed);
        $this->assertSame($original, file_get_contents($file));
    }

    /** @return iterable<string, array{string}> */
    public static function templateHeaders(): iterable
    {
        yield 'short echo' => ['<?= "hello" ?>'];
        yield 'inline PHP' => ['<?php echo "hello"; ?>'];
        yield 'strict types' => ['<?php declare(strict_types=1); echo "hello"; ?>'];
        yield 'empty PHP block' => ["<?php\n?>\nhello"];
        yield 'HTML first' => ['hello'];
    }

    #[DataProvider('templateHeaders')]
    public function testHeaderUpdatesPreserveRenderingAndAreIdempotent(string $template): void
    {
        $dir = $this->views(['index.phtml' => $template]);
        $file = $dir . '/index.phtml';
        $blocker = TemplateDocblocker::syncManaged();
        $engine = new ViewEngine($dir);

        $this->assertTrue($blocker->update($file, ['title' => null]));
        $this->assertSame('hello', (string) $engine->render('index'));
        $this->assertTrue($blocker->update($file, ['title' => 'hello']));
        $contents = file_get_contents($file);
        $this->assertSame('hello', (string) $engine->render('index'));
        $this->assertFalse($blocker->update($file, ['title' => 'hello']));
        $this->assertSame($contents, file_get_contents($file));
    }

    public function testDocblockRewriteRefreshesPreviouslyCachedCode(): void
    {
        $dir = $this->views(['index.phtml' => '<?php echo "before"; ?>']);
        $file = $dir . '/index.phtml';
        $engine = new ViewEngine($dir);

        $this->assertSame('before', (string) $engine->render('index'));
        file_put_contents($file, '<?php echo "after"; ?>');
        TemplateDocblocker::syncManaged()->update($file, []);

        $this->assertSame('after', (string) $engine->render('index'));
    }

    private function managed(string $varLines): string
    {
        $contents =
            "<?php\n\n/**\n * @kaly-template\n * @var \\Kaly\\Tpl\\HtmlView \$v\n" . $varLines . "\n */\n?>\nbody";

        return $this->views(['index.phtml' => $contents]) . '/index.phtml';
    }
}
