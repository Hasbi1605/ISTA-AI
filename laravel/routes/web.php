<?php

use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminPasswordChangeController;
use App\Http\Controllers\Admin\TwoFactorChallengeController;
use App\Http\Controllers\Admin\TwoFactorSetupController;
use App\Http\Controllers\Chat\ChatStreamController;
use App\Http\Controllers\Documents\DocumentExportController;
use App\Http\Controllers\Documents\DocumentPreviewController;
use App\Http\Controllers\Memos\MemoFileController;
use App\Http\Controllers\OnlyOfficeCallbackController;
use App\Http\Controllers\Prompts\PromptReferenceImageController;
use App\Livewire\Admin\AdminAccounts;
use App\Livewire\Admin\AdminDashboard;
use App\Livewire\Admin\AdminDocuments;
use App\Livewire\Admin\AdminErrors;
use App\Livewire\Admin\AdminKnowledge;
use App\Livewire\Admin\AdminUsage;
use App\Livewire\Admin\AdminUsers;
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

Route::get('/guest-prompy', function () {
    session()->put('url.intended', route('chat', ['tab' => 'prompy']));

    return redirect()->route('login');
})->name('guest-prompy');

Route::view('profile', 'profile')
    ->middleware(['auth', 'active'])
    ->name('profile');

Route::get('chat/{id?}', ChatIndex::class)
    ->middleware(['auth', 'active', 'verified', 'throttle:30,1'])
    ->whereNumber('id')
    ->name('chat');

Route::get('chat/stream/{conversationId}', [ChatStreamController::class, 'stream'])
    ->middleware(['auth', 'active', 'verified', 'throttle:60,1'])
    ->whereNumber('conversationId')
    ->name('chat.stream');

Route::post('onlyoffice/callback/{memo}', OnlyOfficeCallbackController::class)
    ->middleware('throttle:120,1')
    ->name('onlyoffice.callback');

Route::get('chat/memos/{memo}/signed-file', [MemoFileController::class, 'signed'])
    ->name('memos.file.signed');

Route::middleware(['auth', 'active', 'verified'])
    ->prefix('chat/memos')
    ->name('memos.')
    ->group(function () {
        Route::get('/{memo}/download', [MemoFileController::class, 'download'])->name('download');
        Route::get('/{memo}/export-pdf', [MemoFileController::class, 'exportPdf'])->name('export.pdf');
        Route::post('/{memo}/force-save', [MemoFileController::class, 'forceSave'])->name('force-save');
    });

Route::middleware(['auth', 'active', 'verified'])
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

Route::middleware(['auth', 'active', 'verified'])
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

Route::middleware(['auth', 'active', 'verified'])
    ->prefix('documents/{document}/preview')
    ->name('documents.preview.')
    ->group(function () {
        Route::get('/status', [DocumentPreviewController::class, 'status'])->name('status');
        Route::get('/stream', [DocumentPreviewController::class, 'stream'])->name('stream');
        Route::get('/html', [DocumentPreviewController::class, 'html'])->name('html');
    });

Route::get('prompts/{prompt}/reference-image/{imageIndex}', [PromptReferenceImageController::class, 'show'])
    ->middleware(['auth', 'active', 'verified'])
    ->whereNumber(['prompt', 'imageIndex'])
    ->name('prompts.reference-image');

Route::middleware(['web'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::middleware('guest')->group(function () {
            Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('login');
            Route::post('/login', [AdminLoginController::class, 'login'])
                ->middleware('throttle:10,1')
                ->name('login.attempt');
        });

        Route::middleware('auth')->group(function () {
            // Logout must remain accessible for any authenticated user (including inactive
            // admins whose session is still valid) so they can clear their session.
            Route::post('/logout', [AdminLoginController::class, 'logout'])->name('logout');
        });

        // Force password change endpoints require the same active-admin guard as the
        // rest of /admin/*. We deliberately omit `admin.password_changed` here to avoid
        // a redirect loop while the flag is still true.
        Route::middleware(['auth', 'verified', 'admin', 'admin.session'])->group(function () {
            Route::get('/password/change', [AdminPasswordChangeController::class, 'show'])->name('password.change');
            Route::post('/password/change', [AdminPasswordChangeController::class, 'update'])
                ->middleware('throttle:10,1')
                ->name('password.update');
        });

        // Two-factor enrollment & challenge. These intentionally omit the `admin.2fa`
        // gate (they are the destination of that gate) but still require an active,
        // verified admin whose password is already rotated.
        Route::middleware(['auth', 'verified', 'admin', 'admin.session', 'admin.password_changed'])->group(function () {
            Route::get('/2fa/setup', [TwoFactorSetupController::class, 'show'])->name('2fa.setup');
            Route::post('/2fa/setup', [TwoFactorSetupController::class, 'confirm'])
                ->middleware('throttle:6,1')
                ->name('2fa.confirm');

            Route::get('/2fa/challenge', [TwoFactorChallengeController::class, 'show'])->name('2fa.challenge');
            Route::post('/2fa/challenge', [TwoFactorChallengeController::class, 'verify'])
                ->middleware('throttle:6,1')
                ->name('2fa.verify');
        });
    });

Route::middleware(['auth', 'verified', 'admin', 'admin.session', 'admin.password_changed', 'admin.2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboard::class)->name('dashboard');
        Route::get('/users', AdminUsers::class)->name('users');
        Route::get('/usage', AdminUsage::class)->name('usage');
        Route::get('/errors', AdminErrors::class)->name('errors');
        Route::get('/documents', AdminDocuments::class)->name('documents');
        Route::get('/knowledge', AdminKnowledge::class)->name('knowledge');
    });

Route::middleware(['auth', 'verified', 'super_admin', 'admin.session', 'admin.password_changed', 'admin.2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/accounts', AdminAccounts::class)->name('accounts');
    });

require __DIR__.'/auth.php';
