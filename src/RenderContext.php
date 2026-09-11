<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Mutable state shared by a top-level render, its layout and its partials.
 *
 * It stores title, blocks, stacks and the pending layout chain.
 */
final class RenderContext
{
    private ?string $layout = null;

    /** @var array<string, mixed> */
    private array $layoutData = [];

    private string $title = '';

    /** @var array<string, Html> */
    private array $blocks = [];

    /** @var array<string, list<Html>> */
    private array $stacks = [];

    /** @var list<array{type: 'block'|'stack', name: string, level: int}> */
    private array $captures = [];

    /** @param array<string, mixed> $data */
    public function setLayout(string $template, array $data = []): void
    {
        $this->layout = $template;
        $this->layoutData = $data;
    }

    public function consumeLayout(): ?string
    {
        $layout = $this->layout;
        $this->layout = null;

        return $layout;
    }

    /** @return array<string, mixed> */
    public function consumeLayoutData(): array
    {
        $data = $this->layoutData;
        $this->layoutData = [];

        return $data;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setBlock(string $name, string|Html $html): void
    {
        $this->assertName($name);
        $this->blocks[$name] = $html instanceof Html ? $html : new Html($html);
    }

    public function getBlock(string $name, string|Html $default = ''): Html
    {
        return $this->blocks[$name] ?? ($default instanceof Html ? $default : new Html($default));
    }

    public function pushStack(string $name, string|Html $html): void
    {
        $this->assertName($name);
        $this->stacks[$name][] = $html instanceof Html ? $html : new Html($html);
    }

    public function getStack(string $name): Html
    {
        return new Html(implode('', array_map('strval', $this->stacks[$name] ?? [])));
    }

    public function begin(string $type, string $name): void
    {
        if ($type !== 'block' && $type !== 'stack') {
            throw new \InvalidArgumentException('Capture type must be block or stack.');
        }

        $this->assertName($name);
        if (!ob_start()) {
            throw new \RuntimeException(sprintf('Unable to start output buffer for view capture "%s".', $name));
        }

        $this->captures[] = ['type' => $type, 'name' => $name, 'level' => ob_get_level()];
    }

    public function end(): void
    {
        if ($this->captures === []) {
            throw new \LogicException('No open view capture to end.');
        }

        $capture = $this->captures[count($this->captures) - 1];
        if (ob_get_level() !== $capture['level']) {
            throw new \LogicException(sprintf(
                'View capture "%s" cannot be closed from another output scope.',
                $capture['name'],
            ));
        }

        array_pop($this->captures);
        $buffer = ob_get_clean();
        if ($buffer === false) {
            throw new \LogicException(sprintf(
                'Output buffer for view capture "%s" is not available.',
                $capture['name'],
            ));
        }

        $html = new Html($buffer);

        if ($capture['type'] === 'block') {
            $this->setBlock($capture['name'], $html);
            return;
        }

        $this->pushStack($capture['name'], $html);
    }

    public function assertClosed(): void
    {
        if ($this->captures !== []) {
            $open = $this->captures[count($this->captures) - 1];
            throw new \LogicException(sprintf('Unclosed view capture "%s".', $open['name']));
        }
    }

    /** @internal Used by ViewEngine to enforce template-local capture balance. */
    public function assertSameCaptures(self $snapshot): void
    {
        if ($this->captures === $snapshot->captures) {
            return;
        }

        if (count($this->captures) > count($snapshot->captures)) {
            $open = $this->captures[count($this->captures) - 1];
            throw new \LogicException(sprintf('Unclosed view capture "%s".', $open['name']));
        }

        throw new \LogicException('A view capture was closed outside the template scope that opened it.');
    }

    /** @internal Used by ViewEngine to roll back state after a failed template. */
    public function restore(self $snapshot): void
    {
        $this->layout = $snapshot->layout;
        $this->layoutData = $snapshot->layoutData;
        $this->title = $snapshot->title;
        $this->blocks = $snapshot->blocks;
        $this->stacks = $snapshot->stacks;
        $this->captures = $snapshot->captures;
    }

    private function assertName(string $name): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid view section name "%s".', $name));
        }
    }
}
