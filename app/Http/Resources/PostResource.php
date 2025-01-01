<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\PostAttachmentResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comments = $this->comments;

        return [
            'id' => $this->id,
            'body' => $this->body,
            'preview' => $this->preview,
            'preview_url' => $this->preview_url,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'user' => new UserResource($this->user),
            'group' => new GroupResource($this->group),
            'attachments' => PostAttachmentResource::collection($this->attachments),
            'num_of_reactions' => $this->reactions_count,
            // 'current_user_has_reaction' =>  $this->reactions()->where('user_id', Auth::id())->exists(), // Query-Based approach but we did eager loading so we don't need to query the database again
            'current_user_has_reaction' => $this->reactions->count() > 0, // Relation-Based approach but we did eager loading so we can use this because we already have the relationship
            'comments' => self::convertCommentsIntoTree($comments),
            'num_of_comments' => count($comments),
        ];
    }

    /**
     * Converts a flat array of comments into a nested tree structure.
     *
     * This method recursively traverses the comments array, grouping child comments under their parent comments.
     * The resulting tree structure is returned as an array.
     *
     * @param \App\Models\Comment[] $comments The flat array of comments to be converted.
     * @param int|null $parentId The ID of the parent comment, or null for top-level comments.
     * @return array The nested tree structure of comments.
     */
    private static function convertCommentsIntoTree($comments, $parentId = null): array
    {
        $commentTree = [];

        foreach ($comments as $comment) {
            if ($comment->parent_id === $parentId) {
                // Find all comment which has parentId as $comment->id
                $children = self::convertCommentsIntoTree($comments, $comment->id);
                $comment->childComments = $children;
                //it works on model level not on the resource level so we need to add numOfComments property to the model itself
                $comment->numOfComments = collect($children)->sum('numOfComments') + count($children);

                $commentTree[] = new CommentResource($comment);
            }
        }

        return $commentTree;
    }
}
