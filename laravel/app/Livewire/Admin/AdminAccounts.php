<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Rules\NoEmailHeaderInjection;
use App\Services\Admin\AdminAccountManagementService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin', ['title' => 'Account Management', 'heading' => 'Account Management'])]
class AdminAccounts extends Component
{
    use WithPagination;

    public string $search = '';

    public string $roleFilter = 'all';

    public string $statusFilter = 'all';

    // Create form
    public bool $showCreateModal = false;

    public string $newName = '';

    public string $newEmail = '';

    public string $newPassword = '';

    public string $newRole = User::ROLE_ADMIN;

    public bool $newForcePasswordChange = true;

    // Edit form
    public ?int $editingUserId = null;

    public string $editName = '';

    public string $editEmail = '';

    public string $editRole = User::ROLE_ADMIN;

    public bool $editForcePasswordChange = false;

    // Reset password
    public ?int $resettingUserId = null;

    public ?string $generatedTemporaryPassword = null;

    // Confirm deactivate
    public ?int $deactivatingUserId = null;

    public string $deactivateReason = '';

    public ?string $flashMessage = null;

    public ?string $flashError = null;

    public function mount(): void
    {
        if (! auth()->user() || ! auth()->user()->isSuperAdmin() || ! auth()->user()->isActive()) {
            abort(403, 'Hanya super admin aktif yang dapat mengakses halaman ini.');
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'roleFilter', 'statusFilter']);
        $this->roleFilter = 'all';
        $this->statusFilter = 'all';
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->reset(['newName', 'newEmail', 'newPassword']);
        $this->newRole = User::ROLE_ADMIN;
        $this->newForcePasswordChange = true;
        $this->resetErrorBag();
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->resetErrorBag();
    }

    public function generateNewPassword(AdminAccountManagementService $service): void
    {
        $this->newPassword = $service->generateTemporaryPassword();
    }

    public function createAccount(AdminAccountManagementService $service): void
    {
        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:120'],
            'newEmail' => ['required', 'email', new NoEmailHeaderInjection, 'max:255'],
            'newPassword' => ['required', 'string', 'min:8', 'max:255'],
            'newRole' => ['required', 'in:admin,super_admin'],
            'newForcePasswordChange' => ['boolean'],
        ], attributes: [
            'newName' => 'nama',
            'newEmail' => 'email',
            'newPassword' => 'password',
            'newRole' => 'role',
        ]);

        try {
            $service->create(
                actor: auth()->user(),
                data: [
                    'name' => $validated['newName'],
                    'email' => $validated['newEmail'],
                    'password' => $validated['newPassword'],
                    'role' => $validated['newRole'],
                    'force_password_change' => $this->newForcePasswordChange,
                ],
                request: request(),
            );
        } catch (ValidationException $e) {
            $this->mapServiceErrors($e, prefix: 'new');

            return;
        }

        $this->showCreateModal = false;
        $this->reset(['newName', 'newEmail', 'newPassword']);
        $this->flashMessage = 'Akun admin berhasil dibuat.';
    }

    public function startEdit(int $userId): void
    {
        $user = $this->resolveAdminTarget($userId);

        $this->editingUserId = $user->id;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->editRole = $user->role;
        $this->editForcePasswordChange = (bool) $user->force_password_change;
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->editingUserId = null;
        $this->resetErrorBag();
    }

    public function saveEdit(AdminAccountManagementService $service): void
    {
        if ($this->editingUserId === null) {
            return;
        }

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:120'],
            'editEmail' => ['required', 'email', new NoEmailHeaderInjection, 'max:255'],
            'editRole' => ['required', 'in:admin,super_admin'],
            'editForcePasswordChange' => ['boolean'],
        ], attributes: [
            'editName' => 'nama',
            'editEmail' => 'email',
            'editRole' => 'role',
        ]);

        $target = $this->resolveAdminTarget($this->editingUserId);

        try {
            $service->update(
                actor: auth()->user(),
                target: $target,
                data: [
                    'name' => $validated['editName'],
                    'email' => $validated['editEmail'],
                    'role' => $validated['editRole'],
                    'force_password_change' => $this->editForcePasswordChange,
                ],
                request: request(),
            );
        } catch (ValidationException $e) {
            $this->mapServiceErrors($e, prefix: 'edit');

            return;
        }

        $this->editingUserId = null;
        $this->flashMessage = 'Akun admin berhasil diperbarui.';
    }

    public function activate(int $userId, AdminAccountManagementService $service): void
    {
        $target = $this->resolveAdminTarget($userId);

        $service->activate(auth()->user(), $target, request());

        $this->flashMessage = sprintf('Akun "%s" diaktifkan.', $target->email);
    }

    public function startDeactivate(int $userId): void
    {
        // Verify target is admin-family before opening modal.
        $this->resolveAdminTarget($userId);

        $this->deactivatingUserId = $userId;
        $this->deactivateReason = '';
        $this->resetErrorBag();
    }

    public function cancelDeactivate(): void
    {
        $this->deactivatingUserId = null;
        $this->deactivateReason = '';
    }

    public function confirmDeactivate(AdminAccountManagementService $service): void
    {
        if ($this->deactivatingUserId === null) {
            return;
        }

        $target = $this->resolveAdminTarget($this->deactivatingUserId);

        try {
            $service->deactivate(
                actor: auth()->user(),
                target: $target,
                reason: $this->deactivateReason ?: null,
                request: request(),
            );
        } catch (ValidationException $e) {
            $this->flashError = $e->validator->errors()->first();
            $this->deactivatingUserId = null;

            return;
        }

        $this->deactivatingUserId = null;
        $this->flashMessage = sprintf('Akun "%s" dinonaktifkan.', $target->email);
    }

    public function startResetPassword(int $userId, AdminAccountManagementService $service): void
    {
        $target = $this->resolveAdminTarget($userId);

        $temporary = $service->generateTemporaryPassword();
        $service->resetPassword(auth()->user(), $target, $temporary, request());

        $this->resettingUserId = $target->id;
        $this->generatedTemporaryPassword = $temporary;
        $this->flashMessage = sprintf('Password sementara untuk "%s" berhasil dibuat.', $target->email);
    }

    public function dismissTemporaryPassword(): void
    {
        $this->resettingUserId = null;
        $this->generatedTemporaryPassword = null;
    }

    public function clearFlash(): void
    {
        $this->flashMessage = null;
        $this->flashError = null;
    }

    public function render(AdminAccountManagementService $service)
    {
        $query = $service->adminAccountsQuery();

        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        if ($this->roleFilter !== 'all' && in_array($this->roleFilter, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], true)) {
            $query->where('role', $this->roleFilter);
        }

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        $summary = [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'inactive' => (clone $query)->where('is_active', false)->count(),
            'force_password_change' => (clone $query)->where('force_password_change', true)->count(),
            'active_super_admins' => (clone $query)
                ->where('role', User::ROLE_SUPER_ADMIN)
                ->where('is_active', true)
                ->count(),
        ];

        $accounts = $query->paginate(15);

        return view('livewire.admin.admin-accounts', [
            'accounts' => $accounts,
            'accountsPerPage' => 15,
            'accountSummary' => $summary,
            'editingUser' => $this->editingUserId ? User::query()->find($this->editingUserId) : null,
            'roleOptions' => [
                'all' => 'Semua',
                User::ROLE_ADMIN => 'Admin',
                User::ROLE_SUPER_ADMIN => 'Super Admin',
            ],
            'statusOptions' => [
                'all' => 'Semua',
                'active' => 'Aktif',
                'inactive' => 'Nonaktif',
            ],
        ]);
    }

    /**
     * Map errors thrown by the service into the appropriate prefixed property names.
     */
    private function mapServiceErrors(ValidationException $exception, string $prefix): void
    {
        $errors = $exception->validator->errors();

        $map = [
            'name' => $prefix.'Name',
            'email' => $prefix.'Email',
            'password' => $prefix.'Password',
            'role' => $prefix.'Role',
            'is_active' => $prefix.'Active',
            'force_password_change' => $prefix.'ForcePasswordChange',
        ];

        foreach ($errors->messages() as $field => $messages) {
            $target = $map[$field] ?? $field;
            foreach ((array) $messages as $message) {
                $this->addError($target, $message);
            }
        }
    }

    /**
     * Resolve the requested user as an admin-family target.
     * Returns 404 if the user does not exist or is a regular user, so that
     * Livewire requests cannot be manipulated to operate on non-admin users.
     */
    private function resolveAdminTarget(int $userId): User
    {
        $user = User::query()->find($userId);

        if (! $user || ! $user->isAdminFamily()) {
            abort(404, 'Akun target bukan akun admin.');
        }

        return $user;
    }
}
