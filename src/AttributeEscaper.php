<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Renders HTML attribute bags and related values using an {@see Escaper}.
 */
final class AttributeEscaper
{
    /** @var list<string> */
    private const BOOLEAN_ATTRIBUTES = [
        'allowfullscreen',
        'async',
        'autofocus',
        'autoplay',
        'checked',
        'controls',
        'default',
        'defer',
        'disabled',
        'formnovalidate',
        'hidden',
        'inert',
        'ismap',
        'itemscope',
        'loop',
        'multiple',
        'muted',
        'nomodule',
        'novalidate',
        'open',
        'playsinline',
        'readonly',
        'required',
        'reversed',
        'selected',
    ];

    /** @var list<string> */
    private const URL_ATTRIBUTES = [
        'action',
        'background',
        'cite',
        'data',
        'formaction',
        'href',
        'longdesc',
        'manifest',
        'poster',
        'src',
        'usemap',
        'xlink:href',
    ];

    public function __construct(
        private readonly Escaper $escaper,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function attrs(array $attributes): Html
    {
        $out = [];

        foreach ($attributes as $name => $value) {
            $name = (string) $name;
            if (!preg_match('/^[A-Za-z_:][A-Za-z0-9_:.\-]*$/', $name)) {
                throw new \InvalidArgumentException(sprintf('Invalid HTML attribute name "%s".', $name));
            }

            if ($value === null) {
                continue;
            }

            $normalizedName = strtolower($name);
            if (str_starts_with($normalizedName, 'on') || $normalizedName === 'srcdoc') {
                throw new \InvalidArgumentException(sprintf('Attribute "%s" requires a dedicated output context.', $name));
            }

            if (in_array($normalizedName, self::URL_ATTRIBUTES, true)) {
                if (!is_string($value) && !$value instanceof \Stringable) {
                    throw new \InvalidArgumentException(sprintf('URL attribute "%s" must be stringable.', $name));
                }
                $out[] = sprintf('%s="%s"', $name, $this->escaper->url($value));
                continue;
            }

            if (is_bool($value) && in_array($normalizedName, self::BOOLEAN_ATTRIBUTES, true)) {
                if ($value) {
                    $out[] = $name;
                }
                continue;
            }

            if (($normalizedName === 'class' || $normalizedName === 'style') && $value === false) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $out[] = sprintf('%s="%s"', $name, $this->escaper->attr($this->attributeValue($normalizedName, $value)));
        }

        return new Html($out === [] ? '' : ' ' . implode(' ', $out));
    }

    /**
     * Build a class list that is safe to echo directly inside an HTML attribute.
     */
    public function classes(mixed ...$values): string
    {
        return $this->escaper->attr($this->classList(...$values));
    }

    /**
     * Build CSS custom-property declarations that are safe to echo directly inside a style attribute.
     *
     * @param array<string, scalar|null> $vars
     */
    public function styleVars(array $vars): Html
    {
        return new Html($this->escaper->attr($this->styleVarsString($vars)));
    }

    private function attributeValue(string $name, mixed $value): mixed
    {
        if ($name === 'class') {
            return $this->classList($value);
        }

        if ($name === 'style' && is_array($value)) {
            /** @var array<string, scalar|null> $value */
            return $this->styleVarsString($value);
        }

        if (is_array($value)) {
            /** @var array<mixed, mixed> $value */
            return $this->listValue($value);
        }

        return $value;
    }

    private function classList(mixed ...$values): string
    {
        $classes = [];

        foreach ($values as $value) {
            $this->appendClass($classes, $value);
        }

        return implode(' ', array_keys($classes));
    }

    /**
     * @param array<string, scalar|null> $vars
     */
    private function styleVarsString(array $vars): string
    {
        $decls = [];

        foreach ($vars as $name => $value) {
            $name = (string) $name;
            if (!preg_match('/^--[A-Za-z0-9_-]+$/', $name)) {
                throw new \InvalidArgumentException(sprintf('Invalid CSS custom property "%s".', $name));
            }

            if ($value === null) {
                continue;
            }

            $string = trim($this->string($value));
            if (preg_match('/[;{}]/', $string)) {
                throw new \InvalidArgumentException(sprintf('Unsafe CSS custom property value for "%s".', $name));
            }

            $decls[] = $name . ': ' . $string;
        }

        return implode('; ', $decls);
    }

    /** @param array<mixed, mixed> $value */
    private function listValue(array $value): string
    {
        $items = [];
        foreach ($value as $item) {
            if ($item === null || $item === false || $item === '') {
                continue;
            }
            if (is_scalar($item) || $item instanceof \Stringable) {
                $items[] = (string) $item;
                continue;
            }

            throw new \InvalidArgumentException(sprintf('Cannot render attribute list item from %s.', get_debug_type($item)));
        }

        return implode(' ', $items);
    }

    /** @param array<string, true> $classes */
    private function appendClass(array &$classes, mixed $value): void
    {
        if ($value === null || $value === false || $value === '') {
            return;
        }

        if (is_string($value) || is_numeric($value)) {
            $parts = preg_split('/\s+/', trim((string) $value));
            foreach ($parts === false ? [] : $parts as $class) {
                if ($class !== '') {
                    $classes[$class] = true;
                }
            }
            return;
        }

        if (is_array($value)) {
            /** @var array<mixed, mixed> $value */
            $this->appendClassArray($classes, $value);
            return;
        }

        if ($value instanceof \Stringable) {
            $this->appendClass($classes, (string) $value);
            return;
        }

        throw new \InvalidArgumentException(sprintf('Cannot render CSS class from %s.', get_debug_type($value)));
    }

    /**
     * @param array<string, true> $classes
     * @param array<mixed, mixed> $value
     */
    private function appendClassArray(array &$classes, array $value): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                if ($item) {
                    $this->appendClass($classes, $key);
                }
                continue;
            }

            $this->appendClass($classes, $item);
        }
    }

    private function string(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(sprintf('Cannot render attribute value from %s.', get_debug_type($value)));
    }
}
