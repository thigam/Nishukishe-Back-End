<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request?->user();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'rating' => $this->rating,
            'status' => $this->status,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'author' => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null,
            'can_update' => $user ? $user->id === $this->user_id : false,
            'can_delete' => $user ? ($user->id === $this->user_id || $user->can('delete', $this->resource)) : false,
            'can_moderate' => $user ? $user->can('moderate', $this->resource) : false,
        ];
    }
}
