<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Post;
use App\Models\User;
use Inertia\Inertia;
use App\Models\Group;
use App\Models\GroupUser;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\PostAttachment;
use Illuminate\Validation\Rule;
use App\Http\Enums\GroupUserRole;
use App\Notifications\RoleChanged;
use App\Http\Enums\GroupUserStatus;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\GroupResource;
use App\Notifications\RequestApproved;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\StoreGroupRequest;
use App\Notifications\InvitationInGroup;
use App\Http\Requests\InviteUsersRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupUserResource;
use App\Notifications\InvitationApproved;
use App\Notifications\RequestToJoinGroup;
use App\Notifications\UserRemovedFromGroup;
use Illuminate\Support\Facades\Notification;
use App\Http\Resources\PostAttachmentResource;

class GroupController extends Controller
{

    /**
     * Display a listing of the resource.
     */
    public function profile(Request $request, Group $group)
    {
        $group->load('currentUserGroup'); // eager-lazy load

        $userId = Auth::id();

        if ($group->hasApprovedUser($userId)) {
            $posts = Post::postsForTimeline($userId, false)
                ->leftJoin('groups AS g', 'g.pinned_post_id', 'posts.id')
                ->where('group_id', $group->id)
                ->orderBy('g.pinned_post_id', 'desc')
                ->orderBy('posts.created_at', 'desc')
                ->paginate(10);
            $posts = PostResource::collection($posts);
        } else {
            return Inertia::render('Group/View', [
                'success' => session('success'),
                'group' => new GroupResource($group),
                'posts' => null,
                'users' => [],
                'requests' => []
            ]);
        }

        if ($request->wantsJson()) {
            return $posts;
        }

        // Get users in group
        $users = User::query()
            ->select(['users.*', 'gu.role', 'gu.status', 'gu.group_id'])
            ->join('group_users AS gu', 'gu.user_id', 'users.id')
            ->orderBy('users.name')
            ->where('group_id', $group->id)
            ->where('gu.status', GroupUserStatus::APPROVED->value) // only approved users
            ->get();

        $requests = $group->pendingUsers()->orderBy('name')->get();

        $photos = PostAttachment::query()
            ->select('post_attachments.*')
            ->join('posts AS p', 'p.id', 'post_attachments.post_id')
            ->where('p.group_id', $group->id)
            ->where('mime', 'like', 'image/%')
            ->latest()
            ->get();

        // dd($photos->toSql());

        return Inertia::render('Group/View', [
            'success' => session('success'),
            'group' => new GroupResource($group),
            'posts' => $posts,
            'users' => GroupUserResource::collection($users),
            'requests' => UserResource::collection($requests),
            'photos' => PostAttachmentResource::collection($photos)
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreGroupRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = Auth::id();
        $group = Group::create($data);

        $groupUserData = [
            'status' => GroupUserStatus::APPROVED->value,
            'role' => GroupUserRole::ADMIN->value,
            'user_id' => Auth::id(),
            'group_id' => $group->id,
            'created_by' => Auth::id()
        ];

        GroupUser::create($groupUserData);
        $group->status = $groupUserData['status'];
        $group->role = $groupUserData['role'];

        return response(new GroupResource($group), 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGroupRequest $request, Group $group)
    {
        $group->update($request->validated());

        return back()->with('success', "Group was updated");
    }

    /**
     * Updates the cover image and thumbnail for a group.
     *
     * This method is responsible for handling the upload and storage of a group's cover image and thumbnail. It first checks if the
     * authenticated user is an admin of the group. If not, it returns a 403 Forbidden response. It then validates the request input,
     * which should include 'cover' and 'thumbnail' fields, both of which are nullable and must be image files.
     *
     * If a cover image is provided, it first deletes the existing cover image (if any) and then stores the new cover image in the
     * 'group-{group_id}' directory on the 'public' disk. The 'cover_path' field of the group is then updated with the new file path.
     *
     * If a thumbnail image is provided, it first deletes the existing thumbnail image (if any) and then stores the new thumbnail image
     * in the 'group-{group_id}' directory on the 'public' disk. The 'thumbnail_path' field of the group is then updated with the new
     * file path.
     *
     * Finally, the method returns a redirect response with a success message.
     *
     * @param Request $request The HTTP request object.
     * @param Group $group The group whose images are being updated.
     * @return \Illuminate\Http\RedirectResponse A redirect response with a success message.
     */
    public function updateImage(Request $request, Group $group)
    {
        if (!$group->isAdmin(Auth::id())) {
            return response("You don't have permission to perform this action", 403);
        }
        $data = $request->validate([
            'cover' => ['nullable', 'image'],
            'thumbnail' => ['nullable', 'image']
        ]);

        $thumbnail = $data['thumbnail'] ?? null;
        /** @var \Illuminate\Http\UploadedFile $cover */
        $cover = $data['cover'] ?? null;

        $success = '';
        if ($cover) {
            if ($group->cover_path) {
                Storage::disk('public')->delete($group->cover_path);
            }
            $path = $cover->store('group-' . $group->id, 'public');
            $group->update(['cover_path' => $path]);
            $success = 'Your cover image was updated';
        }

        if ($thumbnail) {
            if ($group->thumbnail_path) {
                Storage::disk('public')->delete($group->thumbnail_path);
            }
            $path = $thumbnail->store('group-' . $group->id, 'public');
            $group->update(['thumbnail_path' => $path]);
            $success = 'Your thumbnail image was updated';
        }

        //        session('success', 'Cover image has been updated');

        return back()->with('success', $success);
    }

    /**
     * Invites a user to join a group.
     *
     * This method handles the process of inviting a user to join a group. It first checks if the user is already a member of the group, and if so,
     *  deletes the existing group user record. It then generates a random token and creates a new group user record with a pending status,
     *  a user role, and the generated token. The token is set to expire after 24 hours. Finally,
     *  it sends a notification to the user informing them of the invitation.
     *
     * @param InviteUsersRequest $request The request object containing the user and group information.
     * @param Group $group The group to which the user is being invited.
     * @return \Illuminate\Http\RedirectResponse A redirect response with a success message.
     */
    public function inviteUsers(InviteUsersRequest $request, Group $group)
    {
        $data = $request->validated();

        $user = $request->user;

        $groupUser = $request->groupUser;

        if ($groupUser) {
            $groupUser->delete();
        }

        $hours = 24;
        $token = Str::random(256);

        GroupUser::create([
            'status' => GroupUserStatus::PENDING->value,
            'role' => GroupUserRole::USER->value,
            'token' => $token,
            'token_expire_date' => Carbon::now()->addHours($hours),
            'user_id' => $user->id,
            'group_id' => $group->id,
            'created_by' => Auth::id(),
        ]);

        $user->notify(new InvitationInGroup($group, $hours, $token));

        return back()->with('success', 'User was invited to join to group');
    }

    /**
     * Approves a user's invitation to join a group.
     *
     * This method is used to approve a user's request to join a group. It first checks the validity of the provided token,
     * ensuring that the link is not expired or already used. If the token is valid, it updates the status of the GroupUser
     * record to 'approved', marks the token as used, and notifies the group's administrators of the approval. Finally, it
     * redirects the user to the group's profile page with a success message.
     *
     * @param string $token The token associated with the user's invitation.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function approveInvitation(string $token)
    {
        $groupUser = GroupUser::query()
            ->where('token', $token)
            ->first();

        $errorTitle = '';
        if (!$groupUser) {
            $errorTitle = 'The link is not valid';
        } else if ($groupUser->token_used || $groupUser->status === GroupUserStatus::APPROVED->value) {
            $errorTitle = 'The link is already used';
        } else if ($groupUser->token_expire_date < Carbon::now()) {
            $errorTitle = 'The link is expired';
        }

        if ($errorTitle) {
            return \inertia('Error', compact('errorTitle'));
        }

        $groupUser->status = GroupUserStatus::APPROVED->value;
        $groupUser->token_used = Carbon::now();
        $groupUser->save();

        $adminUser = $groupUser->adminUser;

        $adminUser->notify(new InvitationApproved($groupUser->group, $groupUser->user));

        return redirect(route('group.profile', $groupUser->group))
            ->with('success', 'You accepted to join to group "' . $groupUser->group->name . '"');
    }

    /**
     * Handles a user's request to join a group.
     *
     * This method is used when a user wants to join a group. It first checks if the group
     * has auto-approval enabled. If so, it sets the user's status to 'approved' and
     * creates a new GroupUser record with the appropriate status and role. If auto-approval
     * is not enabled, it sets the user's status to 'pending' and sends a notification to
     * the group's administrators. It then creates a new GroupUser record and redirects
     * the user back to the previous page with a success message.
     *
     * @param Group $group The group the user is requesting to join.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function join(Group $group)
    {
        $user = \request()->user();

        $status = GroupUserStatus::APPROVED->value;
        $successMessage = 'You have joined to group "' . $group->name . '"';
        if (!$group->auto_approval) {
            $status = GroupUserStatus::PENDING->value;

            // send notification to admins so we used Notification facade becuse it provides sending mail to many users
            Notification::send($group->adminUsers, new RequestToJoinGroup($group, $user));

            $successMessage = 'Your request has been accepted. You will be notified once you will be approved';
        }

        GroupUser::create([
            'status' => $status,
            'role' => GroupUserRole::USER->value,
            'user_id' => $user->id,
            'group_id' => $group->id,
            'created_by' => $user->id,
        ]);

        return back()->with('success', $successMessage);
    }

    /**
     * Approves or rejects a user's request to join a group.
     *
     * This method is used by group administrators to approve or reject a user's request
     * to join a group. It first checks if the current user is an admin of the group. If
     * so, it updates the status of the GroupUser record to either 'approved' or
     * 'rejected' based on the 'action' parameter. It then notifies the user of the
     * decision.
     *
     * @param Request $request The incoming HTTP request, containing the 'user_id' and 'action' parameters.
     * @param Group $group The group for which the request is being approved or rejected.
     * @return \Illuminate\Http\Response
     */
    public function approveRequest(Request $request, Group $group)
    {
        if (!$group->isAdmin(Auth::id())) {
            return response("You don't have permission to perform this action", 403);
        }

        $data = $request->validate([
            'user_id' => ['required'],
            'action' => ['required']
        ]);

        $groupUser = GroupUser::where('user_id', $data['user_id'])
            ->where('group_id', $group->id)
            ->where('status', GroupUserStatus::PENDING->value)
            ->first();

        if ($groupUser) {
            $approved = false;
            if ($data['action'] === 'approve') {
                $approved = true;
                $groupUser->status = GroupUserStatus::APPROVED->value;
            } else {
                $groupUser->status = GroupUserStatus::REJECTED->value;
            }
            $groupUser->save();

            $user = $groupUser->user;
            $user->notify(new RequestApproved($groupUser->group, $user, $approved));

            return back()->with('success', 'User "' . $user->name . '" was ' . ($approved ? 'approved' : 'rejected'));
        }

        return back();
    }

    /**
     * Removes a user from the specified group.
     *
     * This method is used to remove a user from a group. It first checks if the
     * current user is an admin of the group, and if the user being removed is not
     * the owner of the group. If these conditions are met, it deletes the
     * corresponding GroupUser record and notifies the user that they have been
     * removed from the group.
     *
     * @param Request $request The incoming HTTP request.
     * @param Group $group The group from which the user is being removed.
     * @return \Illuminate\Http\Response
     */
    public function removeUser(Request $request, Group $group)
    {
        if (!$group->isAdmin(Auth::id())) {
            return response("You don't have permission to perform this action", 403);
        }

        $data = $request->validate([
            'user_id' => ['required'],
        ]);

        $user_id = $data['user_id'];
        if ($group->isOwner($user_id)) {
            return response("The owner of the group cannot be removed", 403);
        }

        $groupUser = GroupUser::where('user_id', $user_id)
            ->where('group_id', $group->id)
            ->first();

        if ($groupUser) {
            $user = $groupUser->user;
            $groupUser->delete();

            $user->notify(new UserRemovedFromGroup($group));
        }

        return back();
    }

    /**
     * Changes the role of a user in a group.
     *
     * This method is used to update the role of a user within a specific group. It
     * first checks if the current user is an admin of the group, and if the user
     * whose role is being changed is not the owner of the group. If these
     * conditions are met, it updates the role of the user in the GroupUser model
     * and notifies the user of the role change.
     *
     * @param Request $request The incoming HTTP request.
     * @param Group $group The group in which the user's role is being changed.
     * @return \Illuminate\Http\Response
     */
    public function changeRole(Request $request, Group $group)
    {
        if (!$group->isAdmin(Auth::id())) {
            return response("You don't have permission to perform this action", 403);
        }

        $data = $request->validate([
            'user_id' => ['required'],
            'role' => ['required', Rule::enum(GroupUserRole::class)]
        ]);

        $user_id = $data['user_id'];
        if ($group->isOwner($user_id)) {
            return response("You can't change role of the owner of the group", 403);
        }

        $groupUser = GroupUser::where('user_id', $user_id)
            ->where('group_id', $group->id)
            ->first();

        if ($groupUser) {
            $groupUser->role = $data['role'];
            $groupUser->save();

            $groupUser->user->notify(new RoleChanged($group, $data['role']));
        }

        return back();
    }
}
