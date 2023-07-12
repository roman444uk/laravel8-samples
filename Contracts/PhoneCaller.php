<?php

namespace App\Contracts;


use Illuminate\Validation\ValidationException;

interface PhoneCaller
{
    /**
     * @param string $phone
     *
     * @return array
     * @throws ValidationException
     */
    public function requestCall(string $phone): array;
}
