<?php

namespace App\Domain\Notifications\DTOs;

class PushNotificationMessage
{
    public function __construct(
        public readonly string $eventType,
        public readonly string $title,
        public readonly string $body,
        public readonly string $targetScreen,
        public readonly array $targetParams = [],
        public readonly array $extraData = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            eventType: $data['event_type'] ?? '',
            title: $data['title'] ?? '',
            body: $data['body'] ?? '',
            targetScreen: $data['target_screen'] ?? '',
            targetParams: is_string($data['target_params'] ?? null) ? json_decode($data['target_params'], true) : ($data['target_params'] ?? []),
            extraData: $data['extra_data'] ?? []
        );
    }

    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'title' => $this->title,
            'body' => $this->body,
            'target_screen' => $this->targetScreen,
            'target_params' => $this->targetParams,
            'extra_data' => $this->extraData,
        ];
    }

    /**
     * Prepares unified FCM data payload for Web, iOS, and Android clients.
     */
    public function toFcmDataPayload(): array
    {
        $payload = [
            'event_type' => $this->eventType,
            'title' => $this->title,
            'body' => $this->body,
            'target_screen' => $this->targetScreen,
            'target_params' => json_encode($this->targetParams),
        ];

        foreach ($this->extraData as $key => $val) {
            $payload[$key] = is_array($val) ? json_encode($val) : (string) $val;
        }

        return $payload;
    }
}
