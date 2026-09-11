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

        $managed = $this->extractManagedDocblock($contents);

        if ($managed !== null) {
            if (!$this->syncManagedBlocks) {
                return $contents;
            }

            $merged = $this->mergeVariables($this->variablesFromDocblock($managed), $vars);

            return $this->composeHeader($this->stripManagedHeader($contents), $this->buildDocblock($merged));
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

            $merged[$name] = $this->mergeTypes($existing[$name] ?? null, $observed[$name] ?? null);
        }

        return $merged;
    }

    private function mergeTypes(?string $existing, ?string $observed): string
    {
        $types = [];

        foreach ([$existing, $observed] as $type) {
            if ($type === null || $type === '') {
                continue;
            }

            foreach (explode('|', $type) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $types[$token] = true;
                }
            }
        }

        if (count($types) > 1) {
            unset($types['mixed']);
        }

        return $types === [] ? 'mixed' : implode('|', array_keys($types));
    }

    /** @param array<string, string> $vars */
    private function buildDocblock(array $vars): string
    {
        $lines = [
            '/**',
            ' * ' . self::MARKER,
        ];

        foreach ($vars as $name => $type) {
            $lines[] = sprintf(' * @var %s $%s', $type, $name);
        }

        $lines[] = ' */';

        return implode("\n", $lines);
    }
}
