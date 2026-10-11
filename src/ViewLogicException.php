<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Invalid use of the view engine: unbalanced captures, layout misuse, maximum depths.
 */
final class ViewLogicException extends \LogicException implements ExceptionInterface {}
