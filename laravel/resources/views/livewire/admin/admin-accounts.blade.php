@php
    $formatInt = fn ($value): string => number_format((float) $value, 0, ',', '.');
    $summaryTotal = (int) ($accountSummary['total'] ?? 0);
    $activeAccounts = (int) ($accountSummary['active'] ?? 0);
    $forcedPasswordAccounts = (int) ($accountSummary['force_password_change'] ?? 0);
    $activeSuperAdminAccounts = (int) ($accountSummary['active_super_admins'] ?? 0);
    $accountCards = [
        [
            'label' => 'Total Akun Admin',
            'value' => $summaryTotal,
            'description' => $summaryTotal > 0 ? 'Admin terdaftar' : 'Belum ada admin',
            'tone' => 'primary',
            'icon' => 'M12 11c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v3h16v-3c0-2.66-5.33-4-8-4z',
        ],
        [
            'label' => 'Akun Aktif',
            'value' => $activeAccounts,
            'description' => $activeAccounts > 0 ? 'Punya akses' : 'Tidak ada akses',
            'tone' => $activeAccounts > 0 ? 'success' : 'neutral',
            'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Perlu Reset Password',
            'value' => $forcedPasswordAccounts,
            'description' => $forcedPasswordAccounts > 0 ? $formatInt($forcedPasswordAccounts) . ' perlu reset' : 'Tidak ada reset',
            'tone' => $forcedPasswordAccounts > 0 ? 'warning' : 'neutral',
            'icon' => 'M15 7.5a3 3 0 11-4.95 2.3L4.5 15.35V18h2.65l5.55-5.55A3 3 0 0015 7.5zM15 7.5h.01M17.5 4.5l2 2m0 0l2-2m-2 2V2.75',
        ],
        [
            'label' => 'Super Admin Aktif',
            'value' => $activeSuperAdminAccounts,
            'description' => 'Hak akses tertinggi',
            'tone' => 'primary',
            'icon' => 'M12 3l7.5 4.5v5.25c0 4.5-3.075 7.55-7.5 8.25-4.425-.7-7.5-3.75-7.5-8.25V7.5L12 3z',
        ],
    ];
    $roleLabel = fn (?string $role): string => $role === 'super_admin' ? 'Super Admin' : 'Admin';
    $roleTone = fn (?string $role): string => $role === 'super_admin' ? 'gold' : 'primary';
    $statusMeta = fn ($account): array => (bool) $account->is_active
        ? ['label' => 'Aktif', 'tone' => 'success']
        : ['label' => 'Nonaktif', 'tone' => 'danger'];
    $initials = function (?string $name): string {
        $parts = collect(explode(' ', trim((string) $name)))
            ->filter()
            ->take(2)
            ->map(fn ($part) => mb_substr($part, 0, 1));

        return strtoupper($parts->implode('') ?: 'A');
    };
@endphp

<div class="admin-accounts-page">
    <div class="admin-accounts-hero">
        <div class="max-w-2xl">
            <p class="admin-accounts-eyebrow">Super Admin</p>
            <h2 class="admin-accounts-title">Account Management</h2>
            <p class="admin-accounts-description">
                Kelola akses admin, role, status akun, dan password sementara.
            </p>
        </div>
        <div class="admin-accounts-hero__actions">
            <span class="admin-accounts-access-badge">
                <span></span>
                Akses terbatas
            </span>
        </div>
    </div>

    <div class="admin-accounts-kpi-grid">
        @foreach ($accountCards as $card)
            <article class="admin-accounts-kpi-card admin-accounts-kpi-card--{{ $card['tone'] }}">
                <div class="admin-accounts-kpi-card__header">
                    <span class="admin-accounts-kpi-card__icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $card['icon'] }}"/>
                        </svg>
                    </span>
                    <p class="admin-accounts-kpi-card__label">{{ $card['label'] }}</p>
                </div>
                <div class="admin-accounts-kpi-card__body">
                    <strong>{{ $formatInt($card['value']) }}</strong>
                    <p class="admin-accounts-kpi-card__description">{{ $card['description'] }}</p>
                </div>
            </article>
        @endforeach
    </div>

    @if ($flashMessage)
        <div class="admin-accounts-alert admin-accounts-alert--success">
            <span>{{ $flashMessage }}</span>
            <button type="button" wire:click="clearFlash" aria-label="Tutup pesan">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
        </div>
    @endif

    @if ($flashError)
        <div class="admin-accounts-alert admin-accounts-alert--danger">
            <span>{{ $flashError }}</span>
            <button type="button" wire:click="clearFlash" aria-label="Tutup pesan">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
        </div>
    @endif

    @if ($generatedTemporaryPassword)
        <div class="admin-accounts-temp-password">
            <div class="min-w-0">
                <p>Password sementara berhasil dibuat</p>
                <span>Simpan password ini sekarang. Sistem tidak akan menampilkannya kembali.</span>
                <code>{{ $generatedTemporaryPassword }}</code>
            </div>
            <button type="button" wire:click="dismissTemporaryPassword" aria-label="Tutup password sementara">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
        </div>
    @endif

    <section class="admin-accounts-filter-panel admin-section">
        <div class="admin-accounts-filter-panel__header">
            <h3>Filter</h3>
            <button type="button" wire:click="resetFilters" class="admin-accounts-reset-button">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 4v6h6M20 20v-6h-6M5.5 14a7 7 0 0012 3M18.5 10a7 7 0 00-12-3"/>
                </svg>
                Reset
            </button>
        </div>

        <div class="admin-accounts-filter-grid">
            <label class="admin-accounts-filter">
                <span>Cari</span>
                <input type="search"
                       wire:model.live.debounce.300ms="search"
                       placeholder="Nama atau email"
                       class="admin-accounts-control" />
            </label>

            <label class="admin-accounts-filter">
                <span>Role</span>
                <select wire:model.live="roleFilter" class="admin-accounts-control">
                    @foreach ($roleOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="admin-accounts-filter">
                <span>Status</span>
                <select wire:model.live="statusFilter" class="admin-accounts-control">
                    @foreach ($statusOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </section>

    <div class="admin-accounts-content-grid">
        <section class="admin-accounts-table-panel admin-section">
            <header class="admin-accounts-table-panel__header">
                <div>
                    <h3>Daftar Akun Admin</h3>
                    <p>Menampilkan {{ $accountsPerPage }} akun per halaman pada filter aktif.</p>
                </div>
                <button type="button" wire:click="openCreateModal" class="admin-accounts-primary-button admin-accounts-table-add-button">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M12 4v16m8-8H4"/>
                    </svg>
                    Tambah Akun
                </button>
            </header>

            <div class="admin-accounts-table-panel__body">
                <x-admin.table
                    class="admin-accounts-table"
                    :columns="[
                        ['key' => 'account', 'label' => 'Akun', 'width' => '29%'],
                        ['key' => 'role', 'label' => 'Role', 'width' => '13%'],
                        ['key' => 'status', 'label' => 'Status', 'width' => '13%'],
                        ['key' => 'last_login', 'label' => 'Login Terakhir', 'width' => '18%'],
                        ['key' => 'actions', 'label' => 'Aksi', 'align' => 'center', 'width' => '27%'],
                    ]">
                    @forelse ($accounts as $account)
                        @php
                            $accountStatus = $statusMeta($account);
                        @endphp
                        <tr>
                            <td class="admin-table__td">
                                <div class="admin-users-user-cell">
                                    <span class="admin-user-avatar" aria-hidden="true">{{ $initials($account->name) }}</span>
                                    <div class="min-w-0">
                                        <span class="admin-users-user-cell__name">{{ $account->name }}</span>
                                        <span class="admin-users-user-cell__email">{{ $account->email }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="admin-table__td">
                                <x-admin.badge :tone="$roleTone($account->role)">
                                    {{ $roleLabel($account->role) }}
                                </x-admin.badge>
                            </td>
                            <td class="admin-table__td">
                                <x-admin.badge :tone="$accountStatus['tone']" class="admin-users-status-badge">
                                    <span @class([
                                        'admin-users-status-dot',
                                        'admin-users-status-dot--online' => $account->is_active,
                                        'admin-users-status-dot--offline' => ! $account->is_active,
                                    ])></span>
                                    {{ $accountStatus['label'] }}
                                </x-admin.badge>
                            </td>
                            <td class="admin-table__td">
                                <span class="admin-accounts-muted" title="{{ $account->last_admin_login_at?->toDateTimeString() ?? '-' }}">
                                    {{ $account->last_admin_login_at?->diffForHumans() ?? '-' }}
                                </span>
                            </td>
                            <td class="admin-table__td" data-align="right">
                                <div class="admin-accounts-action-group">
                                    <button type="button"
                                            wire:click="startEdit({{ $account->id }})"
                                            class="admin-accounts-action-button">
                                        Edit
                                    </button>

                                    @if ($account->is_active)
                                        <button type="button"
                                                wire:click="startDeactivate({{ $account->id }})"
                                                class="admin-accounts-toggle admin-accounts-toggle--active"
                                                aria-label="Nonaktifkan {{ $account->email }}"
                                                aria-pressed="true">
                                            <span aria-hidden="true"></span>
                                        </button>
                                    @else
                                        <button type="button"
                                                wire:click="activate({{ $account->id }})"
                                                class="admin-accounts-toggle"
                                                aria-label="Aktifkan {{ $account->email }}"
                                                aria-pressed="false">
                                            <span aria-hidden="true"></span>
                                        </button>
                                    @endif

                                    <button type="button"
                                            wire:click="startResetPassword({{ $account->id }})"
                                            wire:confirm="Reset password admin {{ $account->email }}? Password lama tidak dapat dipakai lagi."
                                            class="admin-accounts-reset-password-button">
                                        Reset Password
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="admin-table__empty">
                                <x-admin.empty-state
                                    title="Belum ada akun admin"
                                    description="Tambahkan akun admin atau super admin baru untuk mulai mengelola sistem." />
                            </td>
                        </tr>
                    @endforelse
                </x-admin.table>

                @if ($accounts->hasPages())
                    <div class="admin-accounts-pagination" wire:key="admin-accounts-pagination-{{ $accounts->currentPage() }}-{{ $accounts->lastPage() }}-{{ $accounts->total() }}-{{ $accounts->firstItem() }}-{{ $accounts->lastItem() }}">
                        {{ $accounts->links('admin.pagination') }}
                    </div>
                @endif
            </div>
        </section>

    </div>

    @if ($editingUserId)
        <div class="admin-accounts-modal" role="dialog" aria-modal="true" aria-labelledby="admin-edit-account-title">
            <button type="button" class="admin-accounts-modal__backdrop" wire:click="cancelEdit" aria-label="Tutup edit akun"></button>
            <section class="admin-accounts-modal__panel">
                <header class="admin-accounts-modal__header">
                    <div>
                        <p class="admin-accounts-modal__eyebrow">Account Update</p>
                        <h3 id="admin-edit-account-title">Edit Akun Admin</h3>
                        <span>{{ $editingUser?->email }}</span>
                    </div>
                    <button type="button" wire:click="cancelEdit" class="admin-accounts-modal__close" aria-label="Tutup edit akun">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </header>

                <div class="admin-accounts-modal__body">
                    <label class="admin-accounts-modal__field">
                        <span>Nama</span>
                        <input type="text" wire:model.defer="editName" class="admin-accounts-control">
                        @error('editName') <em>{{ $message }}</em> @enderror
                    </label>
                    <label class="admin-accounts-modal__field">
                        <span>Email</span>
                        <input type="email" wire:model.defer="editEmail" class="admin-accounts-control">
                        @error('editEmail') <em>{{ $message }}</em> @enderror
                    </label>
                    <label class="admin-accounts-modal__field">
                        <span>Role</span>
                        <select wire:model.defer="editRole" class="admin-accounts-control">
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                        @error('editRole') <em>{{ $message }}</em> @enderror
                    </label>
                    <label class="admin-accounts-checkbox">
                        <input type="checkbox" wire:model.defer="editForcePasswordChange">
                        <span>Wajib ganti password saat login berikutnya</span>
                    </label>
                </div>

                <footer class="admin-accounts-modal__footer">
                    <button type="button" wire:click="cancelEdit" class="admin-accounts-secondary-button">Batal</button>
                    <button type="button" wire:click="saveEdit" class="admin-accounts-primary-button">Simpan Perubahan</button>
                </footer>
            </section>
        </div>
    @endif

    @if ($deactivatingUserId)
        <div class="admin-accounts-modal" role="dialog" aria-modal="true" aria-labelledby="admin-deactivate-account-title">
            <button type="button" class="admin-accounts-modal__backdrop" wire:click="cancelDeactivate" aria-label="Tutup nonaktifkan akun"></button>
            <section class="admin-accounts-modal__panel">
                <header class="admin-accounts-modal__header">
                    <div>
                        <p class="admin-accounts-modal__eyebrow">Access Control</p>
                        <h3 id="admin-deactivate-account-title">Konfirmasi Nonaktifkan Akun</h3>
                        <span>Akun nonaktif tidak dapat login admin.</span>
                    </div>
                    <button type="button" wire:click="cancelDeactivate" class="admin-accounts-modal__close" aria-label="Tutup nonaktifkan akun">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </header>

                <div class="admin-accounts-modal__body">
                    <label class="admin-accounts-modal__field">
                        <span>Alasan</span>
                        <textarea wire:model.defer="deactivateReason" rows="3" class="admin-accounts-control" placeholder="Opsional"></textarea>
                    </label>
                </div>

                <footer class="admin-accounts-modal__footer">
                    <button type="button" wire:click="cancelDeactivate" class="admin-accounts-secondary-button">Batal</button>
                    <button type="button" wire:click="confirmDeactivate" class="admin-accounts-danger-button">Nonaktifkan</button>
                </footer>
            </section>
        </div>
    @endif

    @if ($showCreateModal)
        <div class="admin-accounts-modal" role="dialog" aria-modal="true" aria-labelledby="admin-create-account-title">
            <button type="button" class="admin-accounts-modal__backdrop" wire:click="closeCreateModal" aria-label="Tutup tambah akun"></button>
            <section class="admin-accounts-modal__panel">
                <header class="admin-accounts-modal__header">
                    <div>
                        <p class="admin-accounts-modal__eyebrow">New Admin</p>
                        <h3 id="admin-create-account-title">Tambah Akun Admin</h3>
                        <span>Akun baru otomatis diverifikasi.</span>
                    </div>
                    <button type="button" wire:click="closeCreateModal" class="admin-accounts-modal__close" aria-label="Tutup tambah akun">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </header>

                <div class="admin-accounts-modal__body">
                    <label class="admin-accounts-modal__field">
                        <span>Nama</span>
                        <input type="text" wire:model.defer="newName" class="admin-accounts-control">
                        @error('newName') <em>{{ $message }}</em> @enderror
                    </label>
                    <label class="admin-accounts-modal__field">
                        <span>Email</span>
                        <input type="email" wire:model.defer="newEmail" class="admin-accounts-control" placeholder="email@instansi.go.id">
                        @error('newEmail') <em>{{ $message }}</em> @enderror
                    </label>
                    <label class="admin-accounts-modal__field">
                        <span>Password Sementara</span>
                        <div class="admin-accounts-password-row">
                            <input type="text" wire:model.defer="newPassword" class="admin-accounts-control font-mono">
                            <button type="button" wire:click="generateNewPassword" class="admin-accounts-secondary-button">Generate</button>
                        </div>
                        @error('newPassword') <em>{{ $message }}</em> @enderror
                    </label>
                    <label class="admin-accounts-modal__field">
                        <span>Role</span>
                        <select wire:model.defer="newRole" class="admin-accounts-control">
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                        @error('newRole') <em>{{ $message }}</em> @enderror
                    </label>
                    <label class="admin-accounts-checkbox">
                        <input type="checkbox" wire:model.defer="newForcePasswordChange">
                        <span>Wajibkan ganti password setelah login pertama</span>
                    </label>
                </div>

                <footer class="admin-accounts-modal__footer">
                    <button type="button" wire:click="closeCreateModal" class="admin-accounts-secondary-button">Batal</button>
                    <button type="button" wire:click="createAccount" class="admin-accounts-primary-button">Buat Akun</button>
                </footer>
            </section>
        </div>
    @endif
</div>
