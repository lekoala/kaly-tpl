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

    /** @var array<string, string> */
    private array $paths = [];

    /** @var array<string, mixed> */
    private array $globals = [];

    private string $extension;
    private bool $debug = false;
    private ?TemplateDocblocker $docblocker = null;
    private Escaper $escaper;
    private ValueFormatter $formatter;

    public function __construct(string $viewsPath, string $extension = 'phtml')
    {
        $this->extension = $this->normalizeExtension($extension);
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
        return $this;
    }

    public function locale(string $locale, string $currency = 'EUR', string|\DateTimeZone|null $timezone = null): self
    {
        $this->formatter = new DefaultValueFormatter(
            locale: $locale,
            currency: $currency,
            timezone: is_string($timezone) ? new \DateTimeZone($timezone) : $timezone,
        );

        return $this;
    }

    public function addPath(string $namespace, string $path): self
    {
        if ($namespace !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $namespace)) {
            throw new \InvalidArgumentException(sprintf('Invalid view namespace "%s".', $namespace));
        }

        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException(sprintf('Invalid view path "%s".', $path));
        }

        $this->paths[$namespace] = rtrim($real, DIRECTORY_SEPARATOR);
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
        } catch (\InvalidArgumentException | \RuntimeException) {
            return false;
        }
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = null): Html
    {
        $context = new RenderContext();
        if ($layout !== null) {
            $context->setLayout($layout);
        }

        $content = $this->renderTemplate($template, $data, $context, allowLayout: true);
        $context->setBlock('content', $content);

        /** @var array<string, true> $seenLayouts */
        $seenLayouts = [];
        $layoutDepth = 0;

        while (($nextLayout = $context->consumeLayout()) !== null) {
            $layoutDepth++;
            if ($layoutDepth > self::MAX_LAYOUT_DEPTH) {
                throw new \LogicException(sprintf('Maximum layout depth of %d exceeded.', self::MAX_LAYOUT_DEPTH));
            }
            if (isset($seenLayouts[$nextLayout])) {
                throw new \LogicException(sprintf('Circular layout chain detected at "%s".', $nextLayout));
            }
            $seenLayouts[$nextLayout] = true;

            $content = $this->renderTemplate($nextLayout, ['content' => $content], $context, allowLayout: true);
            $context->setBlock('content', $content);
        }

        $context->assertClosed();
        return $content;
    }

    /** @param array<string, mixed> $data */
    public function renderPartial(string $template, array $data, RenderContext $context): Html
    {
        return $this->renderTemplate($template, $data, $context, allowLayout: false);
    }

    /** @param array<string, mixed> $data */
    private function renderTemplate(string $template, array $data, RenderContext $context, bool $allowLayout): Html
    {
        $file = $this->resolve($template);
        $runtime = new TemplateRuntime($this, $context, $this->escaper, $this->formatter, $allowLayout);
        $data = $this->prepareData($data, $runtime);

        if ($this->debug && $this->docblocker !== null) {
            $this->docblocker->update($file, $data);
        }

        $snapshot = clone $context;
        $bufferLevel = ob_get_level();

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
                if (ob_get_level() !== $__kalyBufferLevel + 1) {
                    throw new \LogicException('Template changed the output buffer stack unexpectedly.');
                }

                $__kalyOutput = ob_get_clean();
                if ($__kalyOutput === false) {
                    throw new \LogicException('Template output buffer is not available.');
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

        return $render($file, $data, $context, $snapshot, $bufferLevel);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareData(array $data, TemplateRuntime $runtime): array
    {
        foreach ([...array_keys($this->globals), ...array_keys($data)] as $name) {
            $name = (string) $name;
            $this->assertDataName($name);
            $this->assertAvailableDataName($name);
        }

        return [
            ...$this->globals,
            ...$data,
            'v' => $runtime,
        ];
    }

    private function assertDataName(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid template variable name "%s".', $name));
        }
    }

    private function assertAvailableDataName(string $name): void
    {
        if ($name === 'v' || str_starts_with($name, '__kaly')) {
            throw new \InvalidArgumentException(sprintf('"$%s" is reserved by the view engine.', $name));
        }
    }

    private function resolve(string $template): string
    {
        [$namespace, $name] = $this->splitName($template);
        if (!array_key_exists($namespace, $this->paths)) {
            throw new \InvalidArgumentException(sprintf('Unknown view namespace "%s".', $namespace));
        }

        $name = trim(str_replace('\\', '/', $name), '/');
        $segments = explode('/', $name);
        if ($name === '' || str_contains($name, "\0") || in_array('..', $segments, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid template name "%s".', $template));
        }

        $extension = '.' . $this->extension;
        if (!str_ends_with($name, $extension)) {
            $name .= $extension;
        }

        $file = $this->paths[$namespace] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $real = realpath($file);
        if ($real === false || !is_file($real)) {
            throw new \RuntimeException(sprintf('View "%s" not found at "%s".', $template, $file));
        }

        if (!$this->isWithinRoot($real, $this->paths[$namespace])) {
            throw new \RuntimeException(sprintf('View "%s" resolves outside of its namespace.', $template));
        }

        return $real;
    }

    /** @return array{string, string} */
    private function splitName(string $template): array
    {
        if (str_starts_with($template, '@')) {
            $parts = explode('/', substr($template, 1), 2);
            return [$parts[0], $parts[1] ?? ''];
        }

        if (str_contains($template, '::')) {
            $parts = explode('::', $template, 2);
            return [$parts[0], $parts[1]];
        }

        return ['', $template];
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = ltrim(trim($extension), '.');
        if ($extension === '' || strpbrk($extension, "/\\\0") !== false) {
            throw new \InvalidArgumentException(sprintf('Invalid template extension "%s".', $extension));
        }

        return $extension;
    }

    private function isWithinRoot(string $file, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $prefix = substr($file, 0, strlen($root));

        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($prefix, $root) === 0 : $prefix === $root;
    }
}
