<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade as IlluminateFacade;

class SystemAttributeValue extends IlluminateFacade
{
    /**
     * Get the registered component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'SystemAttributeValue';
    }
}
