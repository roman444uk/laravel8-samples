<?php

namespace App\Exceptions;

use Exception;

class BusinessException extends Exception
{
    private string $userMessage;

    public function __construct(string $userMessage)
    {
        $this->userMessage = $userMessage;

        parent::__construct("Business exception: $userMessage");
    }

    public function getUserMessage(): string
    {
        return $this->userMessage;
    }
}
