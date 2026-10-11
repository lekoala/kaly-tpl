<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Resolves template names (`users/index`, `@admin/users/index`, `admin::users/index`)
 * to files inside their registered namespace root.
 *
 * Resolved files are cached by template name, so each() items skip realpath() and
 * is_file(). Missing templates are never cached.
 *
 * @internal Used by ViewEngine.
 */
final class TemplateLocator
{
    /** @var array<string, string> */
    private array $paths = [];

    /** @var array<string, string> */
    private array $resolved = [];

    private string $extension;

    public function __construct(string $extension)
    {
        $extension = ltrim(trim($extension), '.');
        if ($extension === '' || strpbrk($extension, "/\\\0") !== false) {
            throw new ViewUsageException(sprintf('Invalid template extension "%s".', $extension));
        }

        $this->extension = $extension;
    }

    public function addPath(string $namespace, string $path): void
    {
        if ($namespace !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $namespace)) {
            throw new ViewUsageException(sprintf('Invalid view namespace "%s".', $namespace));
        }

        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new ViewUsageException(sprintf('Invalid view path "%s".', $path));
        }

        $this->paths[$namespace] = rtrim($real, DIRECTORY_SEPARATOR);
        $this->resolved = [];
    }

    /**
     * @param bool $fresh bypass the cache, so templates can move during development.
     */
    public function resolve(string $template, bool $fresh = false): string
    {
        if (!$fresh && array_key_exists($template, $this->resolved)) {
            return $this->resolved[$template];
        }

        return $this->resolved[$template] = $this->resolveFile($template);
    }

    private function resolveFile(string $template): string
    {
        [$namespace, $name] = $this->splitName($template);
        if (!array_key_exists($namespace, $this->paths)) {
            throw new ViewUsageException(sprintf('Unknown view namespace "%s".', $namespace));
        }

        $name = trim(str_replace('\\', '/', $name), '/');
        $segments = explode('/', $name);
        if ($name === '' || str_contains($name, "\0") || in_array('..', $segments, true)) {
            throw new ViewUsageException(sprintf('Invalid template name "%s".', $template));
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

    private function isWithinRoot(string $file, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $prefix = substr($file, 0, strlen($root));

        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($prefix, $root) === 0 : $prefix === $root;
    }
}
