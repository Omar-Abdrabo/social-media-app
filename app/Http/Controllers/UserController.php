<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Follower;
use Illuminate\Http\Request;
use App\Notifications\FollowUser;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * Handles the logic for following or unfollowing a user.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function follow(Request $request, User $user)
    {
        $data = $request->validate([
            'follow' => ['boolean']
        ]);
        if ($data['follow']) {
            $message = 'You followed user "' . $user->name . '"';
            Follower::create([
                'user_id' => $user->id,
                'follower_id' => Auth::id()
            ]);
        } else {
            $message = 'You unfollowed user "' . $user->name . '"';
            Follower::query()
                ->where('user_id', $user->id)
                ->where('follower_id', Auth::id())
                ->delete();
        }

        $user->notify(new FollowUser(Auth::getUser(), $data['follow']));

        return back()->with('success', $message);
    }
}
