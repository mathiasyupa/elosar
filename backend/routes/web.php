<?php

use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\MedicalAttachmentController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Route::view() instead of a closure on purpose: a closure route can never be
// compiled by `route:cache`, and this whole file used to skip that cache as a
// result — paid on every single request in production, for a route that never
// varies. See docker/entrypoint.sh.
Route::view('/', 'welcome');

/*
 * "Continue with Google". Here rather than in routes/api.php because the OAuth
 * handshake needs the session to validate the `state` Google echoes back. Both
 * routes end in a redirect to medico-web; neither issues a token.
 */
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
    ->middleware('throttle:30,1')
    ->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('google.callback');

Route::view('/dashboard', 'dashboard')->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Authenticated, workspace-scoped serving of medical attachments (PHI).
    Route::get('/medical-attachments/{attachment}/download', [MedicalAttachmentController::class, 'download'])
        ->name('medical-attachments.download');
});

require __DIR__.'/auth.php';
