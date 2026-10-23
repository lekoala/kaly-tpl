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
     * Written types that already cover an inferred one, by inferred type: refinements
     * (`list<User>` for `array`) and wider written types (`iterable<int, User>` for `array`).
     * Generic forms (`array<…>`, `int<…>`, `\Foo<…>`) and `T[]` are covered by refines().
     */
    private const REFINEMENTS = [
        'array' => ['array{', 'list', 'non-empty-array', 'non-empty-list', 'iterable'],
        'string' => ['non-empty-string', 'numeric-string', 'class-string', 'literal-string', 'scalar', 'array-key'],
        'int' => [
            'positive-int',
            'negative-int',
            'non-negative-int',
            'non-positive-int',
            'non-zero-int',
            'scalar',
            'numeric',
            'array-key',
        ],
        'float' => ['scalar', 'numeric'],
        'bool' => ['scalar'],
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
     * Split on a top-level separator only, so `array<string, int|string>` stays whole.
     *
     * @return list<string>
     */
    private function splitUnion(string $type, string $separator = '|'): array
    {
        $types = [];
        $current = '';
        $depth = 0;

        foreach (str_split($type) as $char) {
            if ($char === $separator && $depth === 0) {
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
        $written = $this->unwrap($written);
        if ($written === $inferred) {
            return true;
        }

        if (str_starts_with($written, '?')) {
            return $inferred === 'null' || $this->refines(substr($written, 1), $inferred);
        }

        // A union covers each member; an intersection is more precise than each member.
        foreach (['|', '&'] as $separator) {
            foreach ($this->splitUnion($written, $separator) as $member) {
                if ($member !== $written && $this->refines($member, $inferred)) {
                    return true;
                }
            }
        }

        if ($written === 'object' && str_starts_with($inferred, '\\')) {
            return true;
        }

        if (str_starts_with($written, $inferred . '<')) {
            return true;
        }

        if ($inferred === 'array' && str_ends_with($written, '[]')) {
            return true;
        }

        foreach (self::REFINEMENTS[$inferred] ?? [] as $prefix) {
            if (
                $written === $prefix
                || str_starts_with($written, $prefix . '<')
                || $prefix === 'array{' && str_starts_with($written, $prefix)
            ) {
                return true;
            }
        }

        return false;
    }

    private function unwrap(string $type): string
    {
        while (str_starts_with($type, '(') && str_ends_with($type, ')')) {
            $depth = 0;
            foreach (str_split($type) as $index => $char) {
                $depth += match ($char) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                if ($depth === 0 && $index < (strlen($type) - 1)) {
                    return $type;
                }
            }
            $type = trim(substr($type, 1, -1));
        }

        return $type;
    }
}
