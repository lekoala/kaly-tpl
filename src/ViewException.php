<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Raised when a template fails while rendering.
 *
 * The original exception is preserved as the previous exception and the resolved
 * template include chain is available through {@see self::templateStack()}.
 */
final class ViewException extends \RuntimeException implements ExceptionInterface
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
     * Only exceptions thrown by the engine itself ({@see ExceptionInterface}) are
     * let through: anything else comes from application code and is always wrapped,
     * whatever its class.
     *
     * @param list<string> $templateStack
     */
    public static function wrap(\Throwable $exception, array $templateStack = []): \Throwable
    {
        if ($exception instanceof ExceptionInterface) {
            return $exception;
        }

        $chain = $templateStack === [] ? '' : sprintf(' (template chain: %s)', implode(' > ', $templateStack));

        return new self($exception->getMessage() . $chain, $templateStack, $exception);
    }
}
