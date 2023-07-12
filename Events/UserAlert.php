<?php

namespace App\Events;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;


class UserAlert implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $message;
    public string $position;
    public string $type;
    private User $user;

    /**
     * $position (позиция): top, line, toast
     * $type (тип уведомления): primary, success, danger, warning
     *
     * @return void
     */
    public function __construct(User $user, string $message, string $position, string $type = 'primary')
    {
        $this->message  = $position === 'top' ? Carbon::now()->toDateTimeString().' '.$message : $message;
        $this->position = $position;
        $this->type     = $type;
        $this->user     = $user;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel(sprintf('user-alert.%s', $this->user->id));
    }

    public function broadcastAs()
    {
        return 'alert';
    }
}
