<?php

use App\Livewire\Chat\ChatIndex;
use App\Livewire\Documents\DocumentIndex;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('chat', ChatIndex::class)
    ->middleware(['auth', 'verified'])
    ->name('chat');

Route::get('documents', DocumentIndex::class)
    ->middleware(['auth', 'verified'])
    ->name('documents');

require __DIR__.'/auth.php';
