<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Invalid argument passed to the view engine: template or variable names, paths,
 * escaping or formatting input, data collisions.
 */
final class ViewUsageException extends \InvalidArgumentException implements ExceptionInterface {}
