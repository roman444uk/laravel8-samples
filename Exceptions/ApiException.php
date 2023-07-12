<?php

namespace App\Exceptions;

use Throwable;

class ApiException extends \Exception
{
    public function __construct($message = "", $code = 403, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
