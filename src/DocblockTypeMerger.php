<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Merges a written PHPDoc type with a type inferred at runtime, for managed template docblocks.
 *
 * A runtime observation is less precise than a written type, so it widens a type with
 * new members but never degrades a more precise one (`list<User>` stays, `array` is dropped).
 */
final class DocblockTypeMerger
{
    /**
     * Written types that refine an inferred one, by inferred type. Generic forms
     * (`array<…>`, `iterable<…>`, `int<…>`, `\Foo<…>`) are covered by refines().
     */
    private const REFINEMENTS = [
        'array' => ['array{', 'list', 'non-empty-array', 'non-empty-list'],
        'string' => ['non-empty-string', 'numeric-string', 'class-string', 'literal-string'],
        'int' => ['positive-int', 'negative-int', 'non-negative-int', 'non-positive-int', 'non-zero-int'],
    ];

    public function merge(?string $existing, ?string $observed): string
    {
        $existingTypes = $this->splitUnion($existing ?? '');
        $types = array_fill_keys($existingTypes, true);

        foreach ($this->splitUnion($observed ?? '') as $type) {
            // A runtime observation is less precise than a written type: it never degrades one.
            foreach ($existingTypes as $existingType) {
                if ($this->refines($existingType, $type)) {
                    continue 2;
                }
            }

            $types[$type] = true;
        }

        if (count($types) > 1) {
            unset($types['mixed']);
        }

        return $types === [] ? 'mixed' : implode('|', array_keys($types));
    }

    /**
     * Split a union on its top-level `|` only, so `array<string, int|string>` stays whole.
     *
     * @return list<string>
     */
    private function splitUnion(string $type): array
    {
        $types = [];
        $current = '';
        $depth = 0;

        foreach (str_split($type) as $char) {
            if ($char === '|' && $depth === 0) {
                $types[] = trim($current);
                $current = '';
                continue;
            }

            $depth += match ($char) {
                '<', '{', '(', '[' => 1,
                '>', '}', ')', ']' => $depth > 0 ? -1 : 0,
                default => 0,
            };
            $current .= $char;
        }
        $types[] = trim($current);

        return array_values(array_filter($types, static fn(string $type): bool => $type !== ''));
    }

    /**
     * Whether a written type is a more precise form of an inferred one (`list<User>` for `array`).
     */
    private function refines(string $written, string $inferred): bool
    {
        if (str_starts_with($written, $inferred . '<')) {
            return true;
        }

        foreach (self::REFINEMENTS[$inferred] ?? [] as $prefix) {
            if (str_starts_with($written, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
