<?php

namespace App\Services\Wildberries\Exceptions;

use App\Exceptions\BusinessException;

class ResponseException extends BusinessException
{
    public function __construct(string $userMessage) { parent::__construct($userMessage); }
}
