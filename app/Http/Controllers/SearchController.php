<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use App\Models\Group;
use Illuminate\Http\Request;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\GroupResource;

class SearchController extends Controller
{

    public function search(Request $request, ?string $search = null)
    {
        if (!$search)
            return redirect(route('home'));

        $users = User::query()
            ->where('name', 'like', "%$search%")
            ->orWhere('username', 'like', "%$search%")
            ->latest()
            ->get();

        $groups = Group::query()
            ->where('name', 'like', "%$search%")
            ->orWhere('about', 'like', "%$search%")
            ->latest()
            ->get();

        $posts = Post::postsForTimeline(Auth::id())
            ->where('body', 'like', "%$search%")
            ->paginate(20);

        $posts = PostResource::collection($posts);
        if ($request->wantsJson()) {
            return $posts;
        }


        return inertia('Search', [
            'posts' => $posts,
            'search' => $search,
            'users' => UserResource::collection($users),
            'groups' => GroupResource::collection($groups)
        ]);
    }
}
