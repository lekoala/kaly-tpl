<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Native PHP template renderer.
 *
 * It resolves `.phtml` templates, injects the `$v` runtime, isolates partial
 * scopes, and applies layouts/stacks through a shared render context.
 */
final class ViewEngine
{
    private const MAX_LAYOUT_DEPTH = 32;

    /** @var array<string, mixed> */
    private array $globals = [];

    private TemplateLocator $locator;
    private bool $debug = false;
    private ?TemplateDocblocker $docblocker = null;
    private Escaper $escaper;
    private ValueFormatter $formatter;
    private bool $customFormatter = false;

    public function __construct(string $viewsPath, string $extension = 'phtml')
    {
        $this->locator = new TemplateLocator($extension);
        $this->addPath('', $viewsPath);
        $this->escaper = new DefaultEscaper();
        $this->formatter = new DefaultValueFormatter();
    }

    public function debug(bool $enabled = true): self
    {
        $this->debug = $enabled;
        return $this;
    }

    public function autoDocblock(bool $enabled = true): self
    {
        $this->docblocker = $enabled ? TemplateDocblocker::syncManaged() : null;
        return $this;
    }

    public function docblocks(?TemplateDocblocker $docblocker): self
    {
        $this->docblocker = $docblocker;
        return $this;
    }

    public function escaper(Escaper $escaper): self
    {
        $this->escaper = $escaper;
        return $this;
    }

    public function formatter(ValueFormatter $formatter): self
    {
        $this->formatter = $formatter;
        $this->customFormatter = true;
        return $this;
    }

    public function locale(string $locale, string $currency = 'EUR', string|\DateTimeZone|null $timezone = null): self
    {
        if ($this->customFormatter) {
            throw new ViewLogicException(
                'locale() would replace the custom formatter: call it before formatter(), or configure the custom formatter.',
            );
        }

        $this->formatter = new DefaultValueFormatter(
            locale: $locale,
            currency: $currency,
            timezone: is_string($timezone) ? new \DateTimeZone($timezone) : $timezone,
        );

        return $this;
    }

    public function addPath(string $namespace, string $path): self
    {
        $this->locator->addPath($namespace, $path);
        return $this;
    }

    public function addGlobal(string $name, mixed $value): self
    {
        $this->assertDataName($name);
        $this->assertAvailableDataName($name);

        $this->globals[$name] = $value;
        return $this;
    }

    public function exists(string $template): bool
    {
        try {
            $this->resolve($template);
            return true;
        } catch (\InvalidArgumentException|\RuntimeException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $layoutData
     * @param array<string, mixed> $sharedData
     */
    public function render(
        string $template,
        array $data = [],
        ?string $layout = null,
        array $layoutData = [],
        array $sharedData = [],
    ): Html {
        $context = null;

        try {
            $this->assertDataNames($sharedData);

            $context = new RenderContext($sharedData);
            $context->assertNoSharedCollision($this->globals, 'Shared render data conflicts with globals');
            if ($layout !== null) {
                $context->setLayout($layout, $layoutData);
            }

            $content = $this->renderTemplate($template, $data, $context, allowLayout: true);
            $context->setBlock('content', $content);

            /** @var array<string, true> $seenLayouts */
            $seenLayouts = [];
            $layoutDepth = 0;

            while (($nextLayout = $context->consumeLayout()) !== null) {
                $layoutDepth++;
                if ($layoutDepth > self::MAX_LAYOUT_DEPTH) {
                    throw new ViewLogicException(sprintf(
                        'Maximum layout depth of %d exceeded.',
                        self::MAX_LAYOUT_DEPTH,
                    ));
                }
                if (array_key_exists($nextLayout, $seenLayouts)) {
                    throw new ViewLogicException(sprintf('Circular layout chain detected at "%s".', $nextLayout));
                }
                $seenLayouts[$nextLayout] = true;

                $content = $this->renderTemplate(
                    $nextLayout,
                    [...$context->consumeLayoutData(), 'content' => $content],
                    $context,
                    allowLayout: true,
                );
                $context->setBlock('content', $content);
            }

            $context->assertClosed();

            return $content;
        } catch (\Throwable $exception) {
            throw ViewException::wrap($exception, $context?->failureStack($exception) ?? []);
        }
    }

    /**
     * @internal Called by TemplateRuntime for isolated includes.
     *
     * @param array<string, mixed> $data
     */
    public function renderPartial(string $template, array $data, RenderContext $context): Html
    {
        return $this->renderTemplate($template, $data, $context, allowLayout: false);
    }

    /** @param array<string, mixed> $data */
    private function renderTemplate(string $template, array $data, RenderContext $context, bool $allowLayout): Html
    {
        $file = $this->resolve($template);
        $runtime = new TemplateRuntime($this, $context, $this->escaper, $this->formatter, $allowLayout);

        /** @param array<string, mixed> $__kalyData */
        $render = static function (
            string $__kalyFile,
            array $__kalyData,
            RenderContext $__kalyContext,
            RenderContext $__kalySnapshot,
            int $__kalyBufferLevel,
        ): Html {
            if (!ob_start()) {
                throw new \RuntimeException('Unable to start the template output buffer.');
            }

            try {
                extract($__kalyData, EXTR_SKIP);
                require $__kalyFile;

                $__kalyContext->assertSameCaptures($__kalySnapshot);
                if (ob_get_level() !== ($__kalyBufferLevel + 1)) {
                    throw new ViewLogicException('Template changed the output buffer stack unexpectedly.');
                }

                $__kalyOutput = ob_get_clean();
                if ($__kalyOutput === false) {
                    throw new ViewLogicException('Template output buffer is not available.');
                }

                return new Html($__kalyOutput);
            } catch (\Throwable $__kalyException) {
                while (ob_get_level() > $__kalyBufferLevel) {
                    if (!ob_end_clean()) {
                        break;
                    }
                }
                $__kalyContext->restore($__kalySnapshot);
                throw $__kalyException;
            }
        };

        $context->enterTemplate($template);

        try {
            $data = $this->prepareData($data, $runtime, $context);

            if ($this->debug && $this->docblocker !== null) {
                $this->docblocker->update($file, $data);
            }

            $snapshot = clone $context;
            $bufferLevel = ob_get_level();

            return $render($file, $data, $context, $snapshot, $bufferLevel);
        } catch (\Throwable $exception) {
            $context->recordFailure($exception);
            throw $exception;
        } finally {
            $context->leaveTemplate();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareData(array $data, TemplateRuntime $runtime, RenderContext $context): array
    {
        // Globals and shared data are validated once, by addGlobal() and render().
        $this->assertDataNames($data);

        $context->assertNoSharedCollision($data, 'Template data conflicts with shared render data', withChain: true);

        return [
            ...$this->globals,
            ...$data,
            ...$context->sharedData(),
            'v' => $runtime,
        ];
    }

    /** @param array<array-key, mixed> $data */
    private function assertDataNames(array $data): void
    {
        foreach (array_keys($data) as $name) {
            $name = (string) $name;
            $this->assertDataName($name);
            $this->assertAvailableDataName($name);
        }
    }

    private function assertDataName(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new ViewUsageException(sprintf('Invalid template variable name "%s".', $name));
        }
    }

    private function assertAvailableDataName(string $name): void
    {
        if ($name === 'v' || str_starts_with($name, '__kaly')) {
            throw new ViewUsageException(sprintf('"$%s" is reserved by the view engine.', $name));
        }
    }

    private function resolve(string $template): string
    {
        // Debug mode always hits the filesystem, so templates can move during development.
        return $this->locator->resolve($template, fresh: $this->debug);
    }
}
