<?php

namespace App\Services\Wildberries\Exceptions;

use App\Exceptions\BusinessException;

class TokenRequiredException extends BusinessException
{
    public function __construct()
    {
        parent::__construct(trans('validation.custom.settings.wildberries.api_token.required'));
    }
}
