<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Best-effort runtime value to PHPDoc type inference for template docblocks.
 */
final class DocblockTypeInferrer
{
    public function infer(mixed $value): string
    {
        if ($value instanceof HtmlView) {
            return '\\' . HtmlView::class;
        }

        if (is_iterable($value) && !is_array($value)) {
            return 'iterable';
        }

        if (is_object($value)) {
            $reflection = new \ReflectionClass($value);
            return $reflection->isAnonymous() ? 'object' : '\\' . $value::class;
        }

        return match (true) {
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            $value === null => 'null',
            default => 'mixed',
        };
    }
}
