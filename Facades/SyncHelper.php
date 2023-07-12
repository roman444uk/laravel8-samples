<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade as IlluminateFacade;

class SyncHelper extends IlluminateFacade
{
    /**
     * Get the registered component.
     *
     * @return object
     */
    protected static function getFacadeAccessor()
    {
        return 'SyncHelper';
    }
}
