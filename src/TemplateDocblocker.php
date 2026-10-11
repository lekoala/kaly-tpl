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
        private DocblockTypeMerger $merger = new DocblockTypeMerger(),
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

        $managed = $this->extractManagedDocblock($contents);

        if ($managed !== null) {
            if (!$this->syncManagedBlocks) {
                return $contents;
            }

            [$existing, $descriptions, $otherLines] = $this->parseDocblock($managed);
            $merged = $this->mergeVariables($existing, $vars);

            return $this->composeHeader(
                $this->stripManagedHeader($contents),
                $this->buildDocblock($merged, $descriptions, $otherLines),
            );
        }

        return $this->composeHeader($contents, $this->buildDocblock($vars));
    }

    private function extractManagedDocblock(string $contents): ?string
    {
        if (preg_match('/\A<\?php[ \t]*(?:\r?\n[ \t]*)*(\/\*\*.*?@kaly-template.*?\*\/)/s', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function stripManagedHeader(string $contents): string
    {
        $stripped = preg_replace(
            '/\A<\?php[ \t]*(?:\r?\n[ \t]*)*\/\*\*.*?@kaly-template.*?\*\/[ \t]*(?:\r?\n[ \t]*)*\?>(?:[ \t]*\r?\n)?/s',
            '',
            $contents,
            1,
        );
        if ($stripped !== null && $stripped !== $contents) {
            return $stripped;
        }

        $stripped = preg_replace(
            '/\A<\?php[ \t]*(?:\r?\n[ \t]*)*\/\*\*.*?@kaly-template.*?\*\/(?:[ \t]*\r?\n)?/s',
            '<?php',
            $contents,
            1,
        );

        return $stripped ?? $contents;
    }

    private function composeHeader(string $body, string $docblock): string
    {
        if (str_starts_with($body, '<?php')) {
            return '<?php' . "\n" . $docblock . "\n" . substr($body, strlen('<?php'));
        }

        return '<?php' . "\n" . $docblock . "\n?>\n" . $body;
    }

    private function hasViewRuntimeDocblock(string $contents): bool
    {
        return (
            str_contains($contents, '@var \\Kaly\\Tpl\\HtmlView $v')
            || str_contains($contents, '@var ' . HtmlView::class . ' $v')
        );
    }

    /**
     * Split a managed docblock into its variables, their descriptions and the lines
     * it does not understand, which are kept verbatim instead of being dropped.
     *
     * @return array{array<string, string>, array<string, string>, list<string>}
     */
    private function parseDocblock(string $docblock): array
    {
        $vars = [];
        $descriptions = [];
        $otherLines = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $docblock)) as $line) {
            $line = trim((string) preg_replace('#^\s*(?:/\*\*|\*/|\*(?!/))?|\*/\s*$#', '', $line));
            if ($line === '' || $line === self::MARKER) {
                continue;
            }

            // The type is everything before the variable name, so it may contain spaces (array<string, int>).
            if (preg_match('/^@var\s+(.+?)\s+\$([A-Za-z_][A-Za-z0-9_]*)(?:\s+|$)(.*)$/', $line, $match)) {
                $vars[$match[2]] = $match[1];
                if ($match[3] !== '') {
                    $descriptions[$match[2]] = $match[3];
                }
                continue;
            }

            $otherLines[] = $line;
        }

        return [$vars, $descriptions, $otherLines];
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

            $merged[$name] = $this->merger->merge($existing[$name] ?? null, $observed[$name] ?? null);
        }

        return $merged;
    }

    /**
     * @param array<string, string> $vars
     * @param array<string, string> $descriptions
     * @param list<string> $otherLines
     */
    private function buildDocblock(array $vars, array $descriptions = [], array $otherLines = []): string
    {
        $lines = [
            '/**',
            ' * ' . self::MARKER,
        ];

        foreach ($vars as $name => $type) {
            $description = array_key_exists($name, $descriptions) ? ' ' . $descriptions[$name] : '';
            $lines[] = sprintf(' * @var %s $%s%s', $type, $name, $description);
        }

        foreach ($otherLines as $line) {
            $lines[] = ' * ' . $line;
        }

        $lines[] = ' */';

        return implode("\n", $lines);
    }
}
