<?php

namespace App\Http\Requests\Comments;

use App\Models\Comment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                Comment::STATUS_PENDING,
                Comment::STATUS_APPROVED,
                Comment::STATUS_HIDDEN,
            ])],
        ];
    }
}
