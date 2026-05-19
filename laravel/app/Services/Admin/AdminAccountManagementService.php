<?php

namespace App\Services\Admin;

use App\Models\AdminAccountAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAccountManagementService
{
    public function __construct(private readonly AdminAccountAuditService $audit)
    {
    }

    /**
     * Create a new admin or super admin account.
     */
    public function create(User $actor, array $data, ?Request $request = null): User
    {
        $this->guardActorIsSuperAdmin($actor);

        $role = $this->normalizeRole($data['role'] ?? null);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $name === '' || $password === '') {
            throw ValidationException::withMessages([
                'name' => 'Nama wajib diisi.',
                'email' => 'Email wajib diisi.',
                'password' => 'Password wajib diisi.',
            ]);
        }

        if (strlen($password) < 8) {
            throw ValidationException::withMessages([
                'password' => 'Password minimal 8 karakter.',
            ]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Email sudah digunakan.',
            ]);
        }

        return DB::transaction(function () use ($actor, $name, $email, $password, $role, $data, $request) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'role' => $role,
                'is_active' => true,
                'force_password_change' => (bool) ($data['force_password_change'] ?? true),
            ]);

            $this->audit->record(
                AdminAccountAudit::ACTION_CREATED,
                actor: $actor,
                target: $user,
                after: $this->audit->snapshot($user),
                metadata: ['role' => $role],
                request: $request,
            );

            return $user;
        });
    }

    /**
     * Update an admin account name, email, role, force_password_change, and active status.
     */
    public function update(User $actor, User $target, array $data, ?Request $request = null): User
    {
        $this->guardActorIsSuperAdmin($actor);
        $this->guardTargetIsAdminFamily($target);

        return DB::transaction(function () use ($actor, $target, $data, $request) {
            $before = $this->audit->snapshot($target);
            $target->refresh();

            $newName = isset($data['name']) ? trim((string) $data['name']) : $target->name;
            $newEmail = isset($data['email']) ? strtolower(trim((string) $data['email'])) : $target->email;
            $newRole = isset($data['role']) ? $this->normalizeRole($data['role']) : $target->role;
            $forcePwd = array_key_exists('force_password_change', $data)
                ? (bool) $data['force_password_change']
                : (bool) $target->force_password_change;

            if ($newName === '') {
                throw ValidationException::withMessages([
                    'name' => 'Nama wajib diisi.',
                ]);
            }

            if (! filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'email' => 'Email tidak valid.',
                ]);
            }

            if ($newEmail !== $target->email
                && User::query()->where('email', $newEmail)->where('id', '!=', $target->id)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'Email sudah digunakan akun lain.',
                ]);
            }

            // If we are changing role from super_admin to admin, ensure another active super admin exists.
            if ($target->role === User::ROLE_SUPER_ADMIN && $newRole !== User::ROLE_SUPER_ADMIN) {
                $this->guardLastSuperAdmin($target);
            }

            $roleChanged = $newRole !== $target->role;

            $target->forceFill([
                'name' => $newName,
                'email' => $newEmail,
                'role' => $newRole,
                'force_password_change' => $forcePwd,
            ])->save();

            $after = $this->audit->snapshot($target);

            $this->audit->record(
                AdminAccountAudit::ACTION_UPDATED,
                actor: $actor,
                target: $target,
                before: $before,
                after: $after,
                request: $request,
            );

            if ($roleChanged) {
                $this->audit->record(
                    AdminAccountAudit::ACTION_ROLE_CHANGED,
                    actor: $actor,
                    target: $target,
                    before: ['role' => $before['role']],
                    after: ['role' => $after['role']],
                    request: $request,
                );
            }

            return $target;
        });
    }

    /**
     * Activate an admin account.
     */
    public function activate(User $actor, User $target, ?Request $request = null): User
    {
        $this->guardActorIsSuperAdmin($actor);
        $this->guardTargetIsAdminFamily($target);

        if ($target->is_active) {
            return $target;
        }

        $before = $this->audit->snapshot($target);

        $target->forceFill([
            'is_active' => true,
            'disabled_at' => null,
            'disabled_by' => null,
            'disabled_reason' => null,
        ])->save();

        $this->audit->record(
            AdminAccountAudit::ACTION_ACTIVATED,
            actor: $actor,
            target: $target,
            before: $before,
            after: $this->audit->snapshot($target),
            request: $request,
        );

        return $target;
    }

    /**
     * Deactivate an admin account, blocking access.
     */
    public function deactivate(User $actor, User $target, ?string $reason = null, ?Request $request = null): User
    {
        $this->guardActorIsSuperAdmin($actor);
        $this->guardTargetIsAdminFamily($target);

        if ($target->isAdminFamily() && $target->id === $actor->id) {
            throw ValidationException::withMessages([
                'is_active' => 'Tidak dapat menonaktifkan akun sendiri.',
            ]);
        }

        if ($target->isSuperAdmin() && $target->is_active) {
            $this->guardLastSuperAdmin($target);
        }

        if (! $target->is_active) {
            return $target;
        }

        $before = $this->audit->snapshot($target);

        $target->forceFill([
            'is_active' => false,
            'disabled_at' => now(),
            'disabled_by' => $actor->id,
            'disabled_reason' => $reason ? Str::limit((string) $reason, 250, '') : null,
        ])->save();

        $this->audit->record(
            AdminAccountAudit::ACTION_DEACTIVATED,
            actor: $actor,
            target: $target,
            before: $before,
            after: $this->audit->snapshot($target),
            metadata: ['reason' => $reason],
            request: $request,
        );

        return $target;
    }

    /**
     * Reset password to a temporary value, marks force_password_change=true.
     */
    public function resetPassword(User $actor, User $target, string $temporaryPassword, ?Request $request = null): User
    {
        $this->guardActorIsSuperAdmin($actor);
        $this->guardTargetIsAdminFamily($target);

        if (strlen($temporaryPassword) < 8) {
            throw ValidationException::withMessages([
                'password' => 'Password sementara minimal 8 karakter.',
            ]);
        }

        $target->forceFill([
            'password' => Hash::make($temporaryPassword),
            'force_password_change' => true,
        ])->save();

        $this->audit->record(
            AdminAccountAudit::ACTION_PASSWORD_RESET,
            actor: $actor,
            target: $target,
            metadata: ['by' => $actor->email],
            request: $request,
        );

        return $target;
    }

    public function adminAccountsQuery(): Builder
    {
        return User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->orderByDesc('id');
    }

    public function activeSuperAdminsCount(?int $excludeUserId = null): int
    {
        $query = User::query()
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->where('is_active', true);

        if ($excludeUserId !== null) {
            $query->where('id', '!=', $excludeUserId);
        }

        return $query->count();
    }

    private function guardLastSuperAdmin(User $target): void
    {
        if ($this->activeSuperAdminsCount($target->id) === 0) {
            throw ValidationException::withMessages([
                'role' => 'Tidak dapat menonaktifkan atau menurunkan super admin terakhir.',
            ]);
        }
    }

    private function guardActorIsSuperAdmin(User $actor): void
    {
        if (! $actor->isSuperAdmin() || ! $actor->isActive()) {
            Log::warning('Non super admin attempted admin account management', [
                'actor_id' => $actor->id,
                'role' => $actor->role,
            ]);
            abort(403, 'Hanya super admin aktif yang dapat mengelola akun admin.');
        }
    }

    /**
     * Refuse operations whose target is not part of the admin family.
     * Account management routes must never alter regular users.
     */
    private function guardTargetIsAdminFamily(User $target): void
    {
        if (! $target->isAdminFamily()) {
            Log::warning('Account management attempted on non-admin target', [
                'target_id' => $target->id,
                'role' => $target->role,
            ]);
            abort(404, 'Akun target bukan akun admin.');
        }
    }

    private function normalizeRole(?string $role): string
    {
        $role = $role ?? User::ROLE_ADMIN;

        if (! in_array($role, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true)) {
            throw ValidationException::withMessages([
                'role' => 'Role tidak valid.',
            ]);
        }

        return $role;
    }

    /**
     * Generate a cryptographically secure temporary password.
     */
    public function generateTemporaryPassword(int $length = 14): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
