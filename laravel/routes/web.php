<?php

use App\Livewire\Chat\ChatIndex;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard')
    ->name('dashboard');

Route::get('/guest-chat', function (\Illuminate\Http\Request $request) {
    if ($request->has('q')) {
        session()->put('pending_prompt', $request->input('q'));
    }
    session()->put('url.intended', route('chat'));
    return redirect()->route('login');
})->name('guest-chat');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('chat', ChatIndex::class)
    ->middleware(['auth', 'verified'])
    ->name('chat');

require __DIR__ . '/auth.php';
