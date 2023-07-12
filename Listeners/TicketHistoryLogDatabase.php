<?php

namespace App\Listeners;

use App\Events\TicketHistory;
use App\Models\Ticket\HistoryTicket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class TicketHistoryLogDatabase
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
     * @param  \App\Events\TicketHistory  $event
     * @return void
     */
    public function handle(TicketHistory $event)
    {
       HistoryTicket::create([
            'ticket_id' => $event->ticketId,
            'message_log' => $event->messageLog,
        ]);
    }
}
