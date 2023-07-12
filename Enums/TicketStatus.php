<?php

namespace App\Enums;

enum TicketStatus: string
{

    case NEW = 'new';
    case IN_WORK = 'in_work';
    case WAIT_CLIENT = 'wait_client';
    case SUCCESS_CLOSED = 'success_closed';
    case CLOSED_CLIENT = 'closed_client';

    public static function getAllValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
