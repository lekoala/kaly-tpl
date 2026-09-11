<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Adds or updates managed template docblocks in development.
 *
 * Only docblocks marked with `@kaly-template` are synchronized. Manual docblocks
 * without the marker are left untouched.
 */
final readonly class TemplateDocblocker
{
    private const MARKER = '@kaly-template';

    public function __construct(
        private DocblockTypeInferrer $inferrer = new DocblockTypeInferrer(),
        private bool $syncManagedBlocks = true,
    ) {}

    public static function ensureOnly(): self
    {
        return new self(syncManagedBlocks: false);
    }

    public static function syncManaged(): self
    {
        return new self(syncManagedBlocks: true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $file, array $data): bool
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read template "%s".', $file));
        }

        $vars = $this->variablesFromData($data);
        $newContents = $this->replaceOrPrepend($contents, $vars);

        if ($newContents === $contents) {
            return false;
        }

        if (file_put_contents($file, $newContents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write template "%s".', $file));
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function variablesFromData(array $data): array
    {
        $vars = ['v' => '\\' . HtmlView::class];

        foreach ($data as $name => $value) {
            $name = (string) $name;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                continue;
            }

            $vars[$name] = $this->inferrer->infer($value);
        }

        return $vars;
    }

    /** @param array<string, string> $vars */
    private function replaceOrPrepend(string $contents, array $vars): string
    {
        if ($this->hasViewRuntimeDocblock($contents) && !str_contains($contents, self::MARKER)) {
            return $contents;
        }

        if (preg_match('/^<\?php\s*\/\*\*(.*?)\*\/\s*\?>\s*/s', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $full = $matches[0][0];
            $body = $matches[1][0];

            if (!str_contains($body, self::MARKER)) {
                return $contents;
            }

            if (!$this->syncManagedBlocks) {
                return $contents;
            }

            $merged = $this->mergeVariables($this->variablesFromDocblock($body), $vars);
            return $this->buildBlock($merged) . substr($contents, strlen($full));
        }

        return $this->buildBlock($vars) . $contents;
    }

    private function hasViewRuntimeDocblock(string $contents): bool
    {
        return (
            str_contains($contents, '@var \\Kaly\\Tpl\\HtmlView $v')
            || str_contains($contents, '@var ' . HtmlView::class . ' $v')
        );
    }

    /**
     * @return array<string, string>
     */
    private function variablesFromDocblock(string $body): array
    {
        $vars = [];

        if (preg_match_all(
            '/^\s*\*\s+@var\s+([^\s]+)\s+\$([A-Za-z_][A-Za-z0-9_]*)/m',
            $body,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $match) {
                $vars[$match[2]] = $match[1];
            }
        }

        return $vars;
    }

    /**
     * @param array<string, string> $existing
     * @param array<string, string> $observed
     * @return array<string, string>
     */
    private function mergeVariables(array $existing, array $observed): array
    {
        $merged = ['v' => '\\' . HtmlView::class];

        foreach ([...array_keys($existing), ...array_keys($observed)] as $name) {
            if ($name === 'v') {
                continue;
            }

            $current = $existing[$name] ?? null;
            $seen = $observed[$name] ?? null;

            if ($current === null) {
                $merged[$name] = $seen ?? 'mixed';
                continue;
            }

            if ($seen === null || $seen === 'mixed' || $seen === 'array') {
                $merged[$name] = $current;
                continue;
            }

            if ($seen === 'null' && !str_contains($current, 'null')) {
                $merged[$name] = $current . '|null';
                continue;
            }

            $merged[$name] = $current;
        }

        return $merged;
    }

    /** @param array<string, string> $vars */
    private function buildBlock(array $vars): string
    {
        $lines = [
            '<?php',
            '',
            '/**',
            ' * ' . self::MARKER,
        ];

        foreach ($vars as $name => $type) {
            $lines[] = sprintf(' * @var %s $%s', $type, $name);
        }

        $lines[] = ' */';
        $lines[] = '?>';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
