<?php

namespace App\Domain\Location\Events;

use App\Infrastructure\Persistence\Eloquent\ProviderLocationModel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProviderLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ProviderLocationModel $location,
        public ?int $workId = null
    ) {}

    public function broadcastOn(): array
    {
        $channels = [];
        if ($this->workId) {
            $channels[] = new Channel("work.{$this->workId}.location");
        }
        $channels[] = new Channel("provider.{$this->location->provider_id}.location");
        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'provider_id' => $this->location->provider_id,
            'approximate_zone' => $this->location->approximate_zone,
            'heading' => $this->location->heading,
            'speed_kmh' => $this->location->speed_kmh,
            'created_at' => $this->location->created_at?->toISOString(),
        ];
    }
}
