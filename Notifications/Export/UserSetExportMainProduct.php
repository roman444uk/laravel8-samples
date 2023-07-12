<?php

namespace App\Notifications\Export;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class UserSetExportMainProduct extends Notification
{
    use Queueable;

    protected array $productIds = [];

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(array $productIds)
    {
        $this->productIds = $productIds;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['database'];
    }


    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     *
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'message'  => trans('exports.need_group_products', ['link' => route('product-groups')]),
            'products' => $this->productIds,
        ];
    }
}
