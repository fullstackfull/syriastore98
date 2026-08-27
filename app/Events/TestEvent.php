<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestEvent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function broadcastOn()
    {
        return [
            new Channel('test-channel'),
        ];
    }

    public function broadcastAs()
    {
        return 'test.message';
    }

    public function broadcastWith()
    {
        return [
            'message' => 'Hello from Laravel Reverb',
            'time' => now()->toDateTimeString(),
        ];
    }
}