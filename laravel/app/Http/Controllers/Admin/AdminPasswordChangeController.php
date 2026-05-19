<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccountAudit;
use App\Services\Admin\AdminAccountAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminPasswordChangeController extends Controller
{
    public function __construct(private readonly AdminAccountAuditService $audit)
    {
    }

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAdminFamily()) {
            return redirect()->route('admin.login');
        }

        return view('admin.auth.password-change');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAdminFamily()) {
            return redirect()->route('admin.login');
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('auth.password'),
            ]);
        }

        if (Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Password baru tidak boleh sama dengan password sebelumnya.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'force_password_change' => false,
        ])->save();

        $this->audit->record(
            AdminAccountAudit::ACTION_PASSWORD_RESET,
            actor: $user,
            target: $user,
            metadata: ['by' => 'self_force_change'],
            request: $request,
        );

        return redirect()->intended(route('admin.dashboard'))
            ->with('status', 'Password berhasil diperbarui.');
    }
}
