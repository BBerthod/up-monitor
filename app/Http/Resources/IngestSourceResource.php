<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngestSourceResource extends JsonResource
{
    /**
     * Plain `token` is intentionally omitted — only the SHA-256 hash is exposed.
     * The plain token is revealed once at creation and rotation via `additional(['token' => ...])`.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'token_hash_prefix' => $this->token_hash ? substr($this->token_hash, 0, 12) : null,
            'events_count' => $this->whenCounted('events'),
            'notification_channels' => NotificationChannelResource::collection($this->whenLoaded('notificationChannels')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
