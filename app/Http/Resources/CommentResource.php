<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'comment' => $this->comment,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'num_of_reactions' => $this->reactions_count,
            'num_of_comments' => $this->numOfComments,
            // 'current_user_has_reaction' => $this->reactions()->where('user_id', Auth::id())->exists(), //Query-Based approach but we did eager loading so we don't need to query the database again
            'current_user_has_reaction' => $this->reactions->count() > 0, // Relation-Based approach but we did eager loading so we can use this because we already have the relationship
            'comments' => $this->childComments,
            'user' => [
                "id" => $this->user->id,
                "name" => $this->user->name,
                "username" => $this->user->username,
                "avatar_url" => $this->user->avatar_path ? Storage::url($this->user->avatar_path) : '/img/default_avatar.webp',
            ]
        ];
    }
}
