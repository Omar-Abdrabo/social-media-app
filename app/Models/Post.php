<?php

namespace App\Models;

use App\Models\User;
use App\Models\Group;
use App\Models\Comment;
use App\Models\Reaction;
use App\Models\PostAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Post extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'body', 'group_id', 'preview', 'preview_url'];

    // With casting
    protected $casts = [
        'preview' => 'json',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PostAttachment::class);
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'object');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->latest();
    }

    public function latest5Comments(): HasMany
    {
        return $this->hasMany(Comment::class); //
        // return $this->hasMany(Comment::class)->latest()->limit(5); // lazy loading its load 5 comments for each post (not efficient)
    }

    public static function postsForTimeline($userId, $getLatest = true): Builder
    {
        $query = Post::query() // SELECT * FROM posts
            ->withCount('reactions') // SELECT COUNT(*) from reactions
            ->with([
                'user',
                'group',
                'group.currentUserGroup',
                'attachments',
                'comments' => function ($query) {
                    $query->withCount('reactions'); // SELECT * FROM comments WHERE post_id IN (1, 2, 3...)
                    // SELECT COUNT(*) from reactions
                },
                'comments.user',
                'comments.reactions' => function ($query) use ($userId) {
                    $query->where('user_id', $userId); // SELECT * from reactions WHERE user_id = ?
                },
                'reactions' => function ($query) use ($userId) {
                    $query->where('user_id', $userId); // SELECT * from reactions WHERE user_id = ?
                }
            ]);
        if ($getLatest) {
            $query->latest();
        }

        return $query;
    }

    public function isOwner($userId)
    {
        return $this->user_id == $userId;
    }
}
