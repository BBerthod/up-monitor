<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonitorCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'response_time_ms' => $this->response_time_ms,
            'status_code' => $this->status_code,
            'checked_at' => $this->checked_at?->toIso8601String(),
        ];
    }
}
