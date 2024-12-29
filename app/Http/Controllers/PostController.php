<?php

namespace App\Http\Controllers;

use App\Models\Post;
// use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Comment;
use App\Models\Reaction;
use Illuminate\Http\Request;
use App\Models\PostAttachment;
use Illuminate\Validation\Rule;
use App\Http\Enums\ReactionEnum;
use App\Notifications\PostCreated;
use App\Notifications\PostDeleted;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use Illuminate\Support\Facades\Auth;
use App\Notifications\CommentCreated;
use App\Notifications\CommentDeleted;
use App\Http\Requests\StorePostRequest;
use App\Http\Resources\CommentResource;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\UpdatePostRequest;
use App\Notifications\ReactionAddedOnPost;
use App\Http\Requests\UpdateCommentRequest;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ReactionAddedOnComment;

class PostController extends Controller
{

    /**
     * Displays the details of a specific post.
     *
     * If the post belongs to a group and the current user is not an approved member of that group,
     * the function will return a 403 Forbidden response.
     *
     * The function will load the post's reaction count and the comments associated with the post,
     * including the reaction count for each comment.
     *
     * The function will then return the post data using the PostResource.
     *
     * @param Request $request
     * @param Post $post
     * @return \Inertia\Response
     */
    public function view(Request $request, Post $post)
    {
        if ($post->group_id && !$post->group->hasApprovedUser(Auth::id())) {
            return inertia('Error', [
                'title' => 'Permission Denied',
                'body' => "You don't have permission to view that post"
            ])->toResponse($request)->setStatusCode(403);
        }
        $post->loadCount('reactions');
        $post->load([
            'comments' => function ($query) {
                $query->withCount('reactions'); // SELECT * FROM comments WHERE post_id IN (1, 2, 3...)
                // SELECT COUNT(*) from reactions
            },
        ]);

        return inertia('Post/View', [
            'post' => new PostResource($post)
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePostRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();
        // dd($data);
        DB::beginTransaction();
        $allFilePaths = [];
        try {
            $post = Post::create($data);

            /** @var \Illuminate\Http\UploadedFile[] $files */
            $files = $data['attachments'] ?? [];
            foreach ($files as $file) {
                // dd($file);
                $path = $file->store('attachments/' . $post->id, 'public');
                $allFilePaths[] = $path;
                PostAttachment::create([
                    'post_id' => $post->id,
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'created_by' => $user->id
                ]);
            }

            DB::commit();

            $group = $post->group;

            if ($group) {
                $users = $group->approvedUsers()->where('users.id', '!=', $user->id)->get();
                Notification::send($users, new PostCreated($post, $user, $group));
            }

            $followers = $user->followers;
            Notification::send($followers, new PostCreated($post, $user, null));
        } catch (\Exception $e) {
            foreach ($allFilePaths as $path) {
                Storage::disk('public')->delete($path);
            }
            DB::rollBack();
            throw $e;
        }

        return back();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePostRequest $request, Post $post)
    {
        $user = $request->user();

        DB::beginTransaction();
        $allFilePaths = [];
        try {
            $data = $request->validated();
            // dd($data);
            $post->update($data);

            $deleted_ids = $data['deleted_file_ids'] ?? []; // 1, 2, 3, 4

            $attachments = PostAttachment::query()
                ->where('post_id', $post->id)
                ->whereIn('id', $deleted_ids)
                ->get();

            foreach ($attachments as $attachment) {
                $attachment->delete();
            }

            /** @var \Illuminate\Http\UploadedFile[] $files */
            $files = $data['attachments'] ?? [];
            foreach ($files as $file) {
                $path = $file->store('attachments/' . $post->id, 'public');
                $allFilePaths[] = $path;
                PostAttachment::create([
                    'post_id' => $post->id,
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'created_by' => $user->id
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            foreach ($allFilePaths as $path) {
                Storage::disk('public')->delete($path);
            }
            DB::rollBack();
            throw $e;
        }

        //rerender the page with the updated post "this is not efficient"
        // so we will use axios to do that or  we can use APIs
        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Post $post)
    {
        $id = Auth::id();

        if ($post->isOwner($id) || $post->group && $post->group->isAdmin($id)) {
            $post->delete();

            if (!$post->isOwner($id)) {
                $post->user->notify(new PostDeleted($post->group));
            }

            return back();
        }

        return response("You don't have permission to delete this post", 403);
    }

    /**
     * Download the specified post attachment.
     *
     * @param PostAttachment $attachment The post attachment to download.
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadAttachment(PostAttachment $attachment)
    {
        // TODO check if user has permission to download that attachment

        return response()->download(Storage::disk('public')->path($attachment->path), $attachment->name);
    }

    /**
     * Handle a post reaction request.
     *
     * This method processes a post reaction request, allowing a user to add or remove a reaction to a post.
     * It first validates the request data, then checks if the user has already reacted to the post.
     * If the user has already reacted, the reaction is deleted. Otherwise, a new reaction is created.
     * If the user is not the owner of the post, a notification is sent to the post owner.
     *
     * @param Request $request The incoming request.
     * @param Post $post The post to react to.
     * @return \Illuminate\Http\Response
     */
    public function postReaction(Request $request, Post $post)
    {
        // dd($request->all());
        $data = $request->validate([
            'reaction' => [Rule::enum(ReactionEnum::class)]
        ]);

        $userId = Auth::id();
        // Check if the user has already reacted to the post
        $reaction = Reaction::where('user_id', $userId)
            ->where('object_id', $post->id)
            ->where('object_type', Post::class)
            ->first();

        if ($reaction) {
            $hasReaction = false;
            $reaction->delete();
        } else {
            $hasReaction = true;
            Reaction::create([
                'object_id' => $post->id,
                'object_type' => Post::class,
                'user_id' => $userId,
                'type' => $data['reaction'] // Like or Sad ...etc
            ]);

            if (!$post->isOwner($userId)) {
                $user = User::where('id', $userId)->first();
                $post->user->notify(new ReactionAddedOnPost($post, $user));
            }
        }

        $reactions = Reaction::where('object_id', $post->id)->where('object_type', Post::class)->count();

        return response([
            'num_of_reactions' => $reactions,
            'current_user_has_reaction' => $hasReaction
        ]);
    }

    /**
     * Creates a new comment for the given post.
     *
     * This method handles the creation of a new comment for a post. It first validates the request data,
     * ensuring that a comment text is provided and that the parent_id (if present) references a valid
     * comment. It then creates a new comment record in the database, associating it with the post and
     * the authenticated user. Finally, it notifies the post owner of the new comment and returns a
     * response with the created comment resource.
     *
     * @param Request $request The incoming request containing the comment data.
     * @param Post $post The post to which the comment should be added.
     * @return \Illuminate\Http\Response The created comment resource.
     */
    public function createComment(Request $request, Post $post)
    {
        $data = $request->validate([
            'comment' => ['required'],
            'parent_id' => ['nullable', 'exists:comments,id']
        ]);

        $comment = Comment::create([
            'post_id' => $post->id,
            'comment' => nl2br($data['comment']),
            'user_id' => Auth::id(),
            'parent_id' => $data['parent_id'] ?: null
        ]);

        $post = $comment->post;
        $post->user->notify(new CommentCreated($comment, $post));

        return response(new CommentResource($comment), 201);
    }

    /**
     * Deletes a comment and its child comments.
     *
     * This method handles the deletion of a comment and all of its child comments. It first checks if the
     * authenticated user has permission to delete the comment, which is determined by whether the user
     * is the owner of the comment, the owner of the post, or an admin of the post's group. If the user
     * has permission, the method deletes the comment and all of its child comments, and notifies the
     * comment owner if they are not the deleting user. Finally, it returns a response with the number
     * of deleted comments.
     *
     * @param Comment $comment The comment to be deleted.
     * @return \Illuminate\Http\Response A response with the number of deleted comments.
     */
    public function deleteComment(Comment $comment)
    {
        $post = $comment->post;
        $id = Auth::id();

        if ($comment->isOwner($id) || $post->isOwner($id) || $post->group && $post->group->isAdmin($id)) {

            $allComments = Comment::getAllChildrenComments($comment);
            $deletedCommentCount = count($allComments);

            $comment->delete();

            if (!$comment->isOwner($id)) {
                $comment->user->notify(new CommentDeleted($comment, $post));
            }

            return response(['deleted' => $deletedCommentCount], 200);
        }

        return response("You don't have permission to delete this comment.", 403);
    }

    /**
     * Updates the comment with the provided data.
     *
     * This method updates the comment with the data provided in the `UpdateCommentRequest`. It sets the
     * `comment` field of the comment to the value of the `comment` field in the request, with the `nl2br`
     * function applied to it. It then returns the updated `CommentResource`.
     *
     * @param UpdateCommentRequest $request The request containing the updated comment data.
     * @param Comment $comment The comment to be updated.
     * @return CommentResource The updated comment resource.
     */
    public function updateComment(UpdateCommentRequest $request, Comment $comment)
    {
        $data = $request->validated();

        $comment->update([
            'comment' => nl2br($data['comment'])
        ]);

        return new CommentResource($comment);
    }

    /**
     * Handles the reaction to a comment by the authenticated user.
     *
     * This method allows the authenticated user to add or remove a reaction to a comment. It first validates
     * the request to ensure the reaction type is valid. It then checks if the user has already reacted to the
     * comment, and either deletes the existing reaction or creates a new one. If a new reaction is created
     * and the comment owner is not the authenticated user, a notification is sent to the comment owner.
     * Finally, the method returns the total number of reactions on the comment and whether the current user
     * has a reaction.
     *
     * @param Request $request The request containing the reaction data.
     * @param Comment $comment The comment to be reacted to.
     * @return \Illuminate\Http\Response The response with the reaction data.
     */
    public function commentReaction(Request $request, Comment $comment)
    {
        $data = $request->validate([
            'reaction' => [Rule::enum(ReactionEnum::class)]
        ]);

        $userId = Auth::id();
        $reaction = Reaction::where('user_id', $userId)
            ->where('object_id', $comment->id)
            ->where('object_type', Comment::class)
            ->first();

        if ($reaction) {
            $hasReaction = false;
            $reaction->delete();
        } else {
            $hasReaction = true;
            Reaction::create([
                'object_id' => $comment->id,
                'object_type' => Comment::class,
                'user_id' => $userId,
                'type' => $data['reaction']
            ]);

            if (!$comment->isOwner($userId)) {
                $user = User::where('id', $userId)->first();
                $comment->user->notify(new ReactionAddedOnComment($comment->post, $comment, $user));
            }
        }

        $reactions = Reaction::where('object_id', $comment->id)->where('object_type', Comment::class)->count();

        return response([
            'num_of_reactions' => $reactions,
            'current_user_has_reaction' => $hasReaction
        ]);
    }
}
