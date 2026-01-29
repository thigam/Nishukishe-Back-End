<?php

namespace App\Http\Controllers;

use App\Http\Requests\Comments\ModerateCommentRequest;
use App\Http\Requests\Comments\StoreCommentRequest;
use App\Http\Requests\Comments\UpdateCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Services\Comments\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    public function __construct(private CommentService $comments)
    {
    }

    public function index(Request $request, string $subjectType, string $subjectId)
    {
        $subject = $this->comments->resolveSubject($subjectType, $subjectId);
        $perPage = (int) $request->query('per_page', 10);
        $status = $request->query('status');

        $canModerate = $request->user()
            ? Gate::forUser($request->user())->check('moderateSubject', [Comment::class, $subject])
            : false;

        $paginator = $this->comments->paginateForSubject($subject, $perPage, $canModerate, $status);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'can_moderate' => $canModerate,
            'active_status' => $canModerate ? $status : Comment::STATUS_APPROVED,
        ];

        return CommentResource::collection($paginator)->additional([
            'meta' => $meta,
        ]);
    }

    public function store(StoreCommentRequest $request, string $subjectType, string $subjectId): JsonResponse
    {
        $subject = $this->comments->resolveSubject($subjectType, $subjectId);
        $this->authorize('create', [Comment::class, $subject]);

        $comment = $this->comments->create($request->user(), $subject, $request->validated());

        return (new CommentResource($comment))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateCommentRequest $request, Comment $comment): CommentResource
    {
        $comment->loadMissing('commentable');
        $this->authorize('update', $comment);

        $updated = $this->comments->update($comment, $request->validated(), $request->user());

        return new CommentResource($updated);
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $comment->loadMissing('commentable');
        $this->authorize('delete', $comment);

        $this->comments->delete($comment, $request->user());

        return response()->noContent();
    }

    public function moderate(ModerateCommentRequest $request, Comment $comment): CommentResource
    {
        $comment->loadMissing('commentable');
        $this->authorize('moderate', $comment);

        $updated = $this->comments->moderate($comment, $request->validated('status'), $request->user());

        return new CommentResource($updated);
    }
}
