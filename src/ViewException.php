<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Raised when a template fails while rendering.
 *
 * The original exception is preserved as the previous exception and the resolved
 * template include chain is available through {@see self::templateStack()}.
 */
final class ViewException extends \RuntimeException
{
    /** @param list<string> $templateStack */
    public function __construct(
        string $message,
        private readonly array $templateStack = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return list<string> */
    public function templateStack(): array
    {
        return $this->templateStack;
    }

    /**
     * Wrap a render failure with its template chain, preserving engine-level errors.
     *
     * @param list<string> $templateStack
     */
    public static function wrap(\Throwable $exception, array $templateStack = []): \Throwable
    {
        if (
            $exception instanceof self
            || $exception instanceof \InvalidArgumentException
            || $exception instanceof \LogicException
        ) {
            return $exception;
        }

        $chain = $templateStack === [] ? '' : sprintf(' (template chain: %s)', implode(' > ', $templateStack));

        return new self($exception->getMessage() . $chain, $templateStack, $exception);
    }
}
