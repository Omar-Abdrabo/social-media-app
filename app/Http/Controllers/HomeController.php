<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Inertia\Inertia;
use App\Models\Group;
use Illuminate\Http\Request;
use App\Http\Enums\GroupUserStatus;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\GroupResource;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::id();


        $posts = Post::query()
            ->withCount('reactions')
            // ->withCount('comments')
            ->with([
                'comments' => function ($query) use ($userId) {
                    $query->withCount('reactions');
                },
                'reactions' => function ($query) use ($userId) {
                    $query->where('user_id', $userId);
                }
            ])->paginate(20);

        $posts = PostResource::collection($posts);
        if ($request->wantsJson()) {
            return $posts;
        }

        $groups = Group::query()
            ->with('currentUserGroup')
            ->select(['groups.*'])
            ->join('group_users AS gu', 'gu.group_id', 'groups.id')
            ->where('gu.user_id', Auth::id())
            ->orderBy('gu.role')
            ->orderBy('name', 'desc')
            ->get();

        return Inertia::render(
            'Home',
            [
                'posts' => $posts,
                'groups' => GroupResource::collection($groups),
            ]
        );
    }

    // public function index(Request $request)
    // {
    //     $userId = Auth::id();
    //     $user = $request->user();
    //     $posts = Post::postsForTimeline($userId)
    //         ->select('posts.*')
    //         ->leftJoin('followers AS f', function ($join) use ($userId) {
    //             $join->on('posts.user_id', '=', 'f.user_id')
    //                 ->where('f.follower_id', '=', $userId);
    //         })
    //         ->leftJoin('group_users AS gu', function ($join) use ($userId) {
    //             $join->on('gu.group_id', '=', 'posts.group_id')
    //                 ->where('gu.user_id', '=', $userId)
    //                 ->where('gu.status', GroupUserStatus::APPROVED->value);
    //         })
    //         ->where(function ($query) use ($userId) {
    //             /** @var \Illuminate\Database\Query\Builder $query */
    //             $query->whereNotNull('f.follower_id')
    //                 ->orWhereNotNull('gu.group_id')
    //                 ->orWhere('posts.user_id', $userId)
    //             ;
    //         })
    //         //            ->whereNot('posts.user_id', $userId)
    //         ->paginate(10);

    //     $posts = PostResource::collection($posts);
    //     // to load more functionalityit needs a collection of the posts
    //     if ($request->wantsJson()) {
    //         return $posts;
    //     }

    //     $groups = Group::query()
    //         ->with('currentUserGroup')
    //         ->select(['groups.*'])
    //         ->join('group_users AS gu', 'gu.group_id', 'groups.id')
    //         ->where('gu.user_id', Auth::id())
    //         ->orderBy('gu.role')
    //         ->orderBy('name', 'desc')
    //         ->get();


    //     return Inertia::render('Home', [
    //         'posts' => $posts,
    //         'groups' => GroupResource::collection($groups),
    //         'followings' => UserResource::collection($user->followings)
    //     ]);
    // }
}
