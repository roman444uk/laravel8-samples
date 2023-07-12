<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Shop\SkuService;
use Illuminate\Auth\Events\Registered;

class UserAfterRegisterEvent
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(Registered $event)
    {
        if ($event->user instanceof User) {
            getDefaultPriceList($event->user);

            $skuService = new SkuService();
            $skuService->saveDefaultSkuSettings($event->user->id);
        }
    }
}
