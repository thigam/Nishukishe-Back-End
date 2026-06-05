<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request?->user();
        $isVirtual = str_starts_with((string) $this->id, 'sibling-');
        $authorData = null;

        if ($this->author) {
            $authorData = [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ];
        } elseif ($isVirtual) {
            $authorData = [
                'id' => null,
                'name' => 'Anonymous Reporter',
            ];
        }

        return [
            'id' => $this->id,
            'body' => $this->body,
            'rating' => $this->rating,
            'status' => $this->status,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'author' => $authorData,
            'can_update' => $isVirtual ? false : ($user ? $user->id === $this->user_id : false),
            'can_delete' => $isVirtual ? false : ($user ? ($user->id === $this->user_id || $user->can('delete', $this->resource)) : false),
            'can_moderate' => $isVirtual ? false : ($user ? $user->can('moderate', $this->resource) : false),
        ];
    }
}
