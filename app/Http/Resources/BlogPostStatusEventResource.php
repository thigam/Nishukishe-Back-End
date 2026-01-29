<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BlogPostStatusEvent */
class BlogPostStatusEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'oldStatus' => $this->old_status,
            'newStatus' => $this->new_status,
            'reason' => $this->reason,
            'changedAt' => $this->created_at?->toIso8601String(),
            'actor' => $this->whenLoaded('actor', function () {
                return [
                    'id' => $this->actor->id,
                    'name' => $this->actor->name,
                    'email' => $this->actor->email,
                ];
            }),
        ];
    }
}
