<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BlogPostVersion */
class BlogPostVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'versionNumber' => $this->version_number,
            'title' => $this->title,
            'content' => $this->content,
            'editorNotes' => $this->editor_notes,
            'createdAt' => $this->created_at?->toIso8601String(),
            'createdBy' => $this->whenLoaded('author', function () {
                return [
                    'id' => $this->author->id,
                    'name' => $this->author->name,
                    'email' => $this->author->email,
                ];
            }),
            'isPublished' => $this->is_published,
        ];
    }
}
