<?php

use App\Http\Controllers\Chat\ChatStreamController;
use App\Http\Controllers\CloudStorage\GoogleDriveOAuthController;
use App\Http\Controllers\Documents\DocumentExportController;
use App\Http\Controllers\Documents\DocumentPreviewController;
use App\Http\Controllers\Memos\MemoFileController;
use App\Http\Controllers\OnlyOfficeCallbackController;
use App\Livewire\Chat\ChatIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard')
    ->name('dashboard');

Route::get('/guest-chat', function (Request $request) {
    if ($request->has('q')) {
        session()->put('pending_prompt', $request->input('q'));
    }
    session()->put('url.intended', route('chat'));

    return redirect()->route('login');
})->name('guest-chat');

Route::get('/guest-memo', function () {
    session()->put('url.intended', route('chat', ['tab' => 'memo']));

    return redirect()->route('login');
})->name('guest-memo');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('chat/{id?}', ChatIndex::class)
    ->middleware(['auth', 'verified', 'throttle:30,1'])
    ->whereNumber('id')
    ->name('chat');

Route::get('chat/stream/{conversationId}', [ChatStreamController::class, 'stream'])
    ->middleware(['auth', 'verified', 'throttle:60,1'])
    ->whereNumber('conversationId')
    ->name('chat.stream');

Route::post('onlyoffice/callback/{memo}', OnlyOfficeCallbackController::class)
    ->middleware('throttle:120,1')
    ->name('onlyoffice.callback');

Route::get('chat/memos/{memo}/signed-file', [MemoFileController::class, 'signed'])
    ->name('memos.file.signed');

Route::middleware(['auth', 'verified'])
    ->prefix('chat/memos')
    ->name('memos.')
    ->group(function () {
        Route::get('/{memo}/download', [MemoFileController::class, 'download'])->name('download');
        Route::get('/{memo}/export-pdf', [MemoFileController::class, 'exportPdf'])->name('export.pdf');
        Route::post('/{memo}/force-save', [MemoFileController::class, 'forceSave'])->name('force-save');
    });

Route::middleware(['auth', 'verified'])
    ->prefix('memos')
    ->name('memos.')
    ->group(function () {
        Route::redirect('/', '/chat?tab=memo')->name('index');
        Route::redirect('/create', '/chat?tab=memo')->name('create');
        Route::get('/{memo}', function (string $memo) {
            return redirect()->route('chat', ['tab' => 'memo', 'memo' => $memo]);
        })->name('edit');
        Route::redirect('/{legacyMemoPath}', '/chat?tab=memo')
            ->where('legacyMemoPath', '.*');
    });

Route::middleware(['auth', 'verified'])
    ->prefix('documents')
    ->name('documents.')
    ->group(function () {
        Route::get('/{document}/content-html', [DocumentExportController::class, 'extractContent'])
            ->middleware('throttle:10,1')
            ->name('content-html');
        Route::get('/{document}/extract-tables', [DocumentExportController::class, 'extractTables'])
            ->middleware('throttle:10,1')
            ->name('extract-tables');
        Route::post('/export', [DocumentExportController::class, 'export'])->middleware('throttle:10,1')->name('export');
    });

Route::middleware(['auth', 'verified'])
    ->prefix('documents/{document}/preview')
    ->name('documents.preview.')
    ->group(function () {
        Route::get('/status', [DocumentPreviewController::class, 'status'])->name('status');
        Route::get('/stream', [DocumentPreviewController::class, 'stream'])->name('stream');
        Route::get('/html', [DocumentPreviewController::class, 'html'])->name('html');
    });

Route::middleware(['auth', 'verified'])
    ->prefix('chat/google-drive/oauth')
    ->name('chat.google-drive.oauth.')
    ->group(function () {
        Route::get('/connect', [GoogleDriveOAuthController::class, 'connect'])->name('connect');
        Route::get('/callback', [GoogleDriveOAuthController::class, 'callback'])->name('callback');
    });

Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', function () {
            return response('Admin dashboard placeholder', 200)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        })->name('dashboard');
    });

Route::middleware(['auth', 'verified', 'super_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/ai-config', function () {
            return response('Super admin AI configuration placeholder', 200)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        })->name('ai-config');
    });

require __DIR__.'/auth.php';
