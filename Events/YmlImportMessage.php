<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class YmlImportMessage implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $message;
    public $progress;
    public $error;
    private $user;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(User $user, string $message, int $progress, bool $error = false)
    {
        $this->message  = $message;
        $this->progress = $progress;
        $this->error    = $error;
        $this->user     = $user;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return PrivateChannel
     */
    public function broadcastOn()
    {
        return new PrivateChannel('yml-message.'.$this->user->id);
    }

    /**
     * Имя транслируемого события.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'yml_import';
    }
}
