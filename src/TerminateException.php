<?php

declare(strict_types=1);

namespace Wibiesana\Padi\Core;

use Exception;

/**
 * TerminateException
 * 
 * Thrown to halt execution in FrankenPHP worker mode safely
 * instead of calling exit().
 */
class TerminateException extends Exception {}
