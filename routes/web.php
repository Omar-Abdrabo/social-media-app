<?php

use Inertia\Inertia;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\ProfileController;

Route::get('/', [HomeController::class, 'index'])->middleware(['auth', 'verified'])->name('home');


Route::get('/u/{user:username}', [ProfileController::class, 'index'])
    ->name('profile');


Route::get('/g/{group:slug}', [GroupController::class, 'profile'])
    ->name('group.profile');


Route::middleware('auth')->group(function () {
    // Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile/update-images', [ProfileController::class, 'updateImage'])->name('profile.updateImages');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Groups
    Route::prefix('/group')->group(function () {

        Route::post('/', [GroupController::class, 'store'])
            ->name('group.create');

        Route::post('/update-images/{group:slug}', [GroupController::class, 'updateImage'])
            ->name('group.updateImages');
    });

    // Posts
    Route::prefix('/post')->group(function () {

        Route::get('/{post}', [PostController::class, 'view'])
            ->name('post.view');

        Route::post('', [PostController::class, 'store'])
            ->name('post.create');

        Route::put('/{post}', [PostController::class, 'update'])
            ->name('post.update');

        Route::delete('/{post}', [PostController::class, 'destroy'])
            ->name('post.destroy');

        Route::get('/download/{attachment}', [PostController::class, 'downloadAttachment'])
            ->name('post.download');

        Route::post('/{post}/reaction', [PostController::class, 'postReaction'])
            ->name('post.reaction');

        Route::post('/{post}/comment', [PostController::class, 'createComment'])
            ->name('post.comment.create');
    });

    // Comments
    Route::delete('/comment/{comment}', [PostController::class, 'deleteComment'])
        ->name('comment.delete');

    Route::put('/comment/{comment}', [PostController::class, 'updateComment'])
        ->name('comment.update');

    Route::post('/comment/{comment}/reaction', [PostController::class, 'commentReaction'])
        ->name('comment.reaction');
});

require __DIR__ . '/auth.php';
