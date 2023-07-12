<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketHistory
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $ticketId;

    public $messageLog;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(int $ticketId, string $messageLog)
    {
        $this->ticketId = $ticketId;
        $this->messageLog = $messageLog;
    }
}
