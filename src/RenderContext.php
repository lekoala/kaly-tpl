<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Mutable state shared by a top-level render, its layout and its partials.
 *
 * It stores the per-render shared data, the title, blocks, stacks, the pending
 * layout chain and the render-local template stack used to build error chains.
 */
final class RenderContext
{
    private const MAX_TEMPLATE_DEPTH = 256;

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

    /** @var list<string> */
    private array $templateStack = [];

    /** @var \WeakMap<\Throwable, list<string>> */
    private \WeakMap $failureStacks;

    /** @param array<string, mixed> $sharedData */
    public function __construct(
        private readonly array $sharedData = [],
    ) {
        $this->failureStacks = new \WeakMap();
    }

    /** @return array<string, mixed> */
    public function sharedData(): array
    {
        return $this->sharedData;
    }

    public function enterTemplate(string $template): void
    {
        // Guards against recursive includes, which would otherwise end in an
        // uncatchable memory exhaustion fatal error.
        if (count($this->templateStack) >= self::MAX_TEMPLATE_DEPTH) {
            throw new ViewLogicException(sprintf(
                'Maximum template depth of %d exceeded at "%s", check for a recursive include (template chain: … > %s).',
                self::MAX_TEMPLATE_DEPTH,
                $template,
                implode(' > ', array_slice($this->templateStack, -5)),
            ));
        }

        $this->templateStack[] = $template;
    }

    public function leaveTemplate(): void
    {
        array_pop($this->templateStack);
    }

    /**
     * Reject data whose names are already provided by the shared render data.
     *
     * @param array<array-key, mixed> $data
     */
    public function assertNoSharedCollision(array $data, string $message, bool $withChain = false): void
    {
        $names = array_map('strval', array_keys(array_intersect_key($data, $this->sharedData)));
        if ($names === []) {
            return;
        }

        sort($names);
        $chain = $withChain && $this->templateStack !== []
            ? sprintf(' (template chain: %s)', implode(' > ', $this->templateStack))
            : '';

        throw new ViewUsageException(sprintf(
            '%s: %s%s.',
            $message,
            implode(', ', array_map(static fn(string $name): string => '"' . $name . '"', $names)),
            $chain,
        ));
    }

    public function recordFailure(\Throwable $exception): void
    {
        if (!$this->failureStacks->offsetExists($exception)) {
            $this->failureStacks[$exception] = $this->templateStack;
        }
    }

    /** @return list<string> */
    public function failureStack(\Throwable $exception): array
    {
        return $this->failureStacks[$exception] ?? [];
    }

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

    public function getBlock(string $name, ?Html $default = null): Html
    {
        return $this->blocks[$name] ?? $default ?? new Html('');
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

    /**
     * Open a capture.
     *
     * `$type` must be either `'block'`, which stores a single, replaceable
     * named block read with `block()`, or `'stack'`, which appends to a named
     * stack read with `stack()`. Any other value is rejected.
     *
     * @param string $type the capture kind: `'block'` or `'stack'`.
     * @param string $name the capture name, matching `[A-Za-z][A-Za-z0-9_.-]*`.
     */
    public function begin(string $type, string $name): void
    {
        if ($type !== 'block' && $type !== 'stack') {
            throw new ViewUsageException('Capture type must be block or stack.');
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
            throw new ViewLogicException('No open view capture to end.');
        }

        $capture = $this->captures[count($this->captures) - 1];
        if (ob_get_level() !== $capture['level']) {
            throw new ViewLogicException(sprintf(
                'View capture "%s" cannot be closed from another output scope.',
                $capture['name'],
            ));
        }

        array_pop($this->captures);
        $buffer = ob_get_clean();
        if ($buffer === false) {
            throw new ViewLogicException(sprintf(
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
            throw new ViewLogicException(sprintf('Unclosed view capture "%s".', $open['name']));
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
            throw new ViewLogicException(sprintf('Unclosed view capture "%s".', $open['name']));
        }

        throw new ViewLogicException('A view capture was closed outside the template scope that opened it.');
    }

    /** @internal Used by ViewEngine to roll back state after a failed template. */
    public function restore(self $snapshot): void
    {
        // Restore only template-visible mutable render state. Execution bookkeeping
        // (shared data, template stack, failure stacks) belongs to the render itself
        // and must never be rolled back.
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
            throw new ViewUsageException(sprintf('Invalid view section name "%s".', $name));
        }
    }
}
