<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Marker for every exception thrown by the view engine itself.
 *
 * {@see ViewException::wrap()} lets these through untouched; any other
 * throwable comes from application code and is wrapped with its template chain.
 */
interface ExceptionInterface extends \Throwable {}
