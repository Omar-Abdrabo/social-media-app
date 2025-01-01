<?php

namespace App\Http\Controllers;

use DOMDocument;
// use Illuminate\Http\Request;
use App\Models\Post;
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
use OpenAI\Laravel\Facades\OpenAI;
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

    /**
     * Generates social media post content using the OpenAI API based on a provided prompt.
     *
     * This method takes a prompt from the request, sends it to the OpenAI API to generate formatted content with multiple paragraphs,
     *  and then returns the generated content.
     *
     * @param Request $request The request containing the prompt.
     * @return \Illuminate\Http\Response The response with the generated post content.
     */
    public function aiPostContent(Request $request)
    {
        $prompt =  $request->get('prompt');

        $result = OpenAI::chat()->create([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "Please generate social media post content based on the following prompt.
                        Generated formatted content with multiple paragraphs. Put hashtags after 2 lines from the main content"
                        . PHP_EOL . PHP_EOL . "Prompt: " . PHP_EOL
                        . $prompt
                ],
            ],
        ]);

        // dd($result);
        return response([
            'content' => $result->choices[0]->message->content
            // 'content' => "\"🎉 Exciting news! We're thrilled to announce that we just released a brand new feature on our app/website! 💥 Get ready to experience the next level of convenience and efficiency with this game-changing addition. 🚀 Try it out now and let us know what you think! 😍 #NewFeatureAlert #UpgradeYourExperience\""
        ]);
    }

    /**
     * Fetches the Open Graph (OG) tags from a given URL.
     *
     * This method takes a URL from the request, fetches the HTML content of the page,
     * parses the HTML to extract the OG tags, and returns an array of the extracted tags.
     *
     * @param Request $request The request containing the URL.
     * @return array The array of extracted OG tags.
     */
    public function fetchUrlPreview(Request $request)
    {
        $data = $request->validate([
            'url' => 'url'
        ]);
        $url = $data['url'];

        $html = file_get_contents($url);

        $dom = new DOMDocument();

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);

        // Load HTML content into the DOMDocument
        $dom->loadHTML($html);

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(false);

        $ogTags = [];
        $metaTags = $dom->getElementsByTagName('meta');
        foreach ($metaTags as $tag) {
            $property = $tag->getAttribute('property');
            if (str_starts_with($property, 'og:')) {
                $ogTags[$property] = $tag->getAttribute('content');
            }
        }

        return $ogTags;
    }

    /**
     * Pins or unpins a post for a group or a user.
     *
     * This method handles the logic for pinning or unpinning a post. If the request is for a group,
     * it checks if the user is an admin of the group. If the request is for a user, it updates the
     * user's pinned post. The method returns a success message indicating whether the post was
     * pinned or unpinned.
     *
     * @param Request $request The HTTP request containing the 'forGroup' parameter.
     * @param Post $post The post to be pinned or unpinned.
     * @return \Illuminate\Http\RedirectResponse A redirect response with a success message.
     */
    public function pinUnpin(Request $request, Post $post)
    {
        $request->validate([
            'forGroup' => 'boolean'
        ]);

        $forGroup = $request->get('forGroup', false);
        $group = $post->group;

        if ($forGroup && !$group) {
            return response("Invalid Request", 400);
        }

        if ($forGroup && !$group->isAdmin(Auth::id())) {
            return response("You don't have permission to perform this action", 403);
        }

        $pinned = false;
        if ($forGroup && $group->isAdmin(Auth::id())) {
            if ($group->pinned_post_id === $post->id) {
                $group->pinned_post_id = null;
            } else {
                $pinned = true;
                $group->pinned_post_id = $post->id;
            }
            $group->save();
        }

        if (!$forGroup) {
            $user = $request->user();
            if ($user->pinned_post_id === $post->id) {
                $user->pinned_post_id = null;
            } else {
                $pinned = true;
                $user->pinned_post_id = $post->id;
            }
            $user->save();
        }

        return back()->with('success', 'Post was successfully ' . ($pinned ? 'pinned' : 'unpinned'));
    }
}
