<?php

namespace App\Notifications\Export;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DuplicateNotVariationsError extends Notification
{
    use Queueable;

    protected string $sku;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(string $sku)
    {
        $this->sku = $sku;
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
            'message' => trans('exports.errors.duplicate_not_variations', ['sku' => $this->sku]),
            'sku'     => $this->sku,
        ];
    }
}
