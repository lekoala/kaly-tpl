<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Concrete `$v` runtime available inside templates.
 *
 * The runtime is mostly a façade: escaping is delegated to {@see Escaper},
 * display formatting to {@see ValueFormatter}, and layout state to {@see RenderContext}.
 */
final class TemplateRuntime implements HtmlView
{
    public function __construct(
        private readonly ViewEngine $engine,
        private readonly RenderContext $context,
        private readonly Escaper $escaper,
        private readonly ValueFormatter $formatter,
        private readonly bool $allowLayout = true,
    ) {}

    public function e(mixed $value): string
    {
        return $this->escaper->html($value);
    }

    public function attr(mixed $value): string
    {
        return $this->escaper->attr($value);
    }

    public function url(string|\Stringable|null $value): string
    {
        return $this->escaper->url($value);
    }

    public function attrs(array $attributes): Html
    {
        return $this->escaper->attrs($attributes);
    }

    public function classes(mixed ...$values): string
    {
        return $this->escaper->classes(...$values);
    }

    public function json(mixed $value): Html
    {
        return $this->escaper->json($value);
    }

    public function styleVars(array $vars): Html
    {
        return $this->escaper->styleVars($vars);
    }

    public function html(string $trustedHtml): Html
    {
        return new Html($trustedHtml);
    }

    public function raw(Html $html): Html
    {
        return $html;
    }

    public function date(\DateTimeInterface|string|null $value, string $style = 'medium'): Html
    {
        return new Html($this->e($this->formatter->date($value, $style)));
    }

    public function time(\DateTimeInterface|string|null $value, string $style = 'short'): Html
    {
        return new Html($this->e($this->formatter->time($value, $style)));
    }

    public function datetime(\DateTimeInterface|string|null $value, string $style = 'medium'): Html
    {
        return new Html($this->e($this->formatter->datetime($value, $style)));
    }

    public function money(int|float|string|null $amount, ?string $currency = null): Html
    {
        return new Html($this->e($this->formatter->money($amount, $currency)));
    }

    public function number(int|float|string|null $value, int $decimals = 0): Html
    {
        return new Html($this->e($this->formatter->number($value, $decimals)));
    }

    public function percent(int|float|string|null $value, int $decimals = 0): Html
    {
        return new Html($this->e($this->formatter->percent($value, $decimals)));
    }

    public function inc(string $template, array $data = []): Html
    {
        return $this->engine->renderPartial($template, $data, $this->context);
    }

    public function each(
        iterable $items,
        string $template,
        string|EachOptions $as = 'item',
        ?string $empty = null,
        array $data = [],
    ): Html {
        $options = $this->eachOptions($as, $empty, $data);

        $length = is_countable($items) ? count($items) : null;
        $state = new LoopState();

        $hasCurrent = false;
        $currentKey = null;
        $currentItem = null;
        $previousItem = null;
        $html = '';
        $index = 0;

        foreach ($items as $key => $item) {
            if (!$hasCurrent) {
                $hasCurrent = true;
                $currentKey = $key;
                $currentItem = $item;
                continue;
            }

            $html .= $this->inc($template, [
                ...$options->data,
                $options->as => $currentItem,
                'key' => $currentKey,
                'loop' => new Loop(
                    new LoopFrame($index, $currentKey, false, $previousItem, $item),
                    $length,
                    $options->baseDepth,
                    $options->maxDepth,
                    $state,
                ),
            ]);

            $previousItem = $currentItem;
            $currentKey = $key;
            $currentItem = $item;
            $index++;
        }

        if (!$hasCurrent) {
            return $options->empty === null ? new Html('') : $this->inc($options->empty, $options->data);
        }

        $html .= $this->inc($template, [
            ...$options->data,
            $options->as => $currentItem,
            'key' => $currentKey,
            'loop' => new Loop(
                new LoopFrame($index, $currentKey, true, $previousItem, null),
                $length,
                $options->baseDepth,
                $options->maxDepth,
                $state,
            ),
        ]);

        return new Html($html);
    }

    /** @param array<string, mixed> $data */
    private function eachOptions(string|EachOptions $as, ?string $empty, array $data): EachOptions
    {
        if ($as instanceof EachOptions) {
            if ($empty !== null || $data !== []) {
                throw new \InvalidArgumentException(
                    'Do not combine EachOptions with the short-form empty/data arguments.',
                );
            }

            return $as;
        }

        return new EachOptions(as: $as, empty: $empty, data: $data);
    }

    /** @param array<string, mixed> $data */
    public function layout(string $template, array $data = []): void
    {
        if (!$this->allowLayout) {
            throw new \LogicException(
                'Layouts can only be selected by a top-level view or another layout, not by a partial include.',
            );
        }

        $this->context->setLayout($template, $data);
    }

    public function title(?string $title = null): string
    {
        if ($title !== null) {
            $this->context->setTitle($title);
        }

        return $this->context->getTitle();
    }

    public function start(string $name): void
    {
        $this->context->begin('block', $name);
    }

    public function push(string $name): void
    {
        $this->context->begin('stack', $name);
    }

    public function end(): void
    {
        $this->context->end();
    }

    public function block(string $name, string|Html $default = ''): Html
    {
        return $this->context->getBlock($name, $default);
    }

    public function stack(string $name): Html
    {
        return $this->context->getStack($name);
    }

    public function content(): Html
    {
        return $this->block('content');
    }
}
