<div>
    <div class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">Super Admin</p>
                <h2 class="admin-page-title mt-1">Account Management</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Kelola akun admin dan super admin. Buat akun baru, ubah role, aktifkan atau nonaktifkan akses, dan reset password sementara.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-admin.badge tone="gold">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                    Akses terbatas
                </x-admin.badge>
                <x-admin.badge tone="primary">
                    Total Super Admin Aktif: {{ $totalActiveSuperAdmins }}
                </x-admin.badge>
                <button type="button"
                        wire:click="openCreateModal"
                        class="inline-flex h-9 items-center gap-2 rounded-lg bg-ista-primary px-3 text-xs font-semibold uppercase tracking-wider text-amber-300 transition hover:bg-stone-800">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4"/>
                    </svg>
                    Tambah Akun
                </button>
            </div>
        </div>
    </div>

    @if ($flashMessage)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50/80 p-3 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200">
            <div class="flex items-start justify-between gap-3">
                <span>{{ $flashMessage }}</span>
                <button type="button" wire:click="clearFlash" class="text-emerald-700/80 hover:text-emerald-900 dark:text-emerald-200/80 dark:hover:text-emerald-100">
                    <span class="sr-only">Tutup</span>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
    @endif

    @if ($flashError)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50/80 p-3 text-sm text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
            <div class="flex items-start justify-between gap-3">
                <span>{{ $flashError }}</span>
                <button type="button" wire:click="clearFlash" class="text-rose-600 hover:text-rose-800 dark:text-rose-200/80 dark:hover:text-rose-100">
                    <span class="sr-only">Tutup</span>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
    @endif

    @if ($generatedTemporaryPassword)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold">Password sementara berhasil dibuat</p>
                    <p class="mt-1 text-xs">Salin password berikut sekarang. Sistem tidak akan menampilkannya kembali.</p>
                    <code class="mt-2 inline-block rounded bg-white/80 px-3 py-1.5 font-mono text-sm tracking-widest text-stone-900 dark:bg-gray-900 dark:text-amber-200">
                        {{ $generatedTemporaryPassword }}
                    </code>
                </div>
                <button type="button" wire:click="dismissTemporaryPassword" class="text-amber-800/80 hover:text-amber-900 dark:text-amber-200/80 dark:hover:text-amber-100">
                    <span class="sr-only">Tutup</span>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
    @endif

    <x-admin.section
        title="Daftar Akun Admin"
        description="Akun admin dan super admin yang terdaftar dalam sistem.">
        <x-slot name="actions">
            <div class="flex flex-wrap items-center gap-2">
                <label class="admin-filter">
                    <span class="admin-filter__label">Cari</span>
                    <input type="text"
                           wire:model.live.debounce.300ms="search"
                           placeholder="Nama atau email"
                           class="admin-filter__control">
                </label>
                <label class="admin-filter">
                    <span class="admin-filter__label">Role</span>
                    <select wire:model.live="roleFilter" class="admin-filter__control">
                        <option value="all">Semua</option>
                        <option value="admin">Admin</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                </label>
                <label class="admin-filter">
                    <span class="admin-filter__label">Status</span>
                    <select wire:model.live="statusFilter" class="admin-filter__control">
                        <option value="all">Semua</option>
                        <option value="active">Aktif</option>
                        <option value="inactive">Nonaktif</option>
                    </select>
                </label>
            </div>
        </x-slot>

        <x-admin.table :columns="[
            ['key' => 'name', 'label' => 'Akun'],
            ['key' => 'role', 'label' => 'Role'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'last_login', 'label' => 'Login Terakhir', 'align' => 'right'],
            ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right'],
        ]">
            @forelse ($accounts as $account)
                <tr>
                    <td class="admin-table__td">
                        <div class="flex flex-col">
                            <span class="text-sm font-semibold text-stone-700 dark:text-gray-200">{{ $account->name }}</span>
                            <span class="text-[11px] text-stone-400 dark:text-gray-500">{{ $account->email }}</span>
                            @if ($account->force_password_change)
                                <span class="mt-1 inline-flex w-fit items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                    Wajib ganti password
                                </span>
                            @endif
                        </div>
                    </td>
                    <td class="admin-table__td">
                        <x-admin.badge :tone="$account->role === 'super_admin' ? 'gold' : 'primary'">
                            {{ $account->role === 'super_admin' ? 'Super Admin' : 'Admin' }}
                        </x-admin.badge>
                    </td>
                    <td class="admin-table__td">
                        @if ($account->is_active)
                            <x-admin.badge tone="success">Aktif</x-admin.badge>
                        @else
                            <x-admin.badge tone="danger">Nonaktif</x-admin.badge>
                        @endif
                    </td>
                    <td class="admin-table__td" data-align="right">
                        <span class="text-xs text-stone-500 dark:text-gray-400" title="{{ $account->last_admin_login_at?->toDateTimeString() ?? '—' }}">
                            {{ $account->last_admin_login_at?->diffForHumans() ?? '—' }}
                        </span>
                    </td>
                    <td class="admin-table__td" data-align="right">
                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            <button type="button"
                                    wire:click="startEdit({{ $account->id }})"
                                    class="inline-flex h-8 items-center gap-1 rounded-md border border-stone-200 bg-white px-2 text-[11px] font-semibold uppercase tracking-wider text-stone-600 transition hover:border-ista-primary/30 hover:text-ista-primary dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-ista-primary/40 dark:hover:text-amber-300">
                                Edit
                            </button>

                            @if ($account->is_active)
                                <button type="button"
                                        wire:click="startDeactivate({{ $account->id }})"
                                        class="inline-flex h-8 items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 text-[11px] font-semibold uppercase tracking-wider text-rose-700 transition hover:bg-rose-100 dark:border-rose-900/40 dark:bg-rose-950/40 dark:text-rose-200">
                                    Nonaktifkan
                                </button>
                            @else
                                <button type="button"
                                        wire:click="activate({{ $account->id }})"
                                        class="inline-flex h-8 items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 text-[11px] font-semibold uppercase tracking-wider text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-900/40 dark:bg-emerald-950/40 dark:text-emerald-200">
                                    Aktifkan
                                </button>
                            @endif

                            <button type="button"
                                    wire:click="startResetPassword({{ $account->id }})"
                                    wire:confirm="Reset password admin {{ $account->email }}? Password lama tidak dapat dipakai lagi."
                                    class="inline-flex h-8 items-center gap-1 rounded-md border border-stone-200 bg-white px-2 text-[11px] font-semibold uppercase tracking-wider text-stone-600 transition hover:border-ista-primary/30 hover:text-ista-primary dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-ista-primary/40 dark:hover:text-amber-300">
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
            <div class="mt-4">
                {{ $accounts->links() }}
            </div>
        @endif
    </x-admin.section>

    {{-- Edit drawer --}}
    @if ($editingUserId)
        @php
            $editingUser = \App\Models\User::query()->find($editingUserId);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/40 p-4 backdrop-blur-sm dark:bg-black/60" role="dialog" aria-modal="true">
            <div class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <h3 class="text-base font-semibold text-stone-900 dark:text-gray-100">Edit Akun Admin</h3>
                <p class="mt-1 text-xs text-stone-500 dark:text-gray-400">{{ $editingUser?->email }}</p>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 dark:text-gray-400">Nama</label>
                        <input type="text" wire:model.defer="editName" class="mt-1 admin-filter__control w-full">
                        @error('editName') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 dark:text-gray-400">Email</label>
                        <input type="email" wire:model.defer="editEmail" class="mt-1 admin-filter__control w-full">
                        @error('editEmail') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 dark:text-gray-400">Role</label>
                        <select wire:model.defer="editRole" class="mt-1 admin-filter__control w-full">
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                        @error('editRole') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-xs text-stone-600 dark:text-gray-300">
                        <input type="checkbox" wire:model.defer="editForcePasswordChange" class="h-4 w-4 rounded border-stone-300 text-ista-primary focus:ring-ista-primary/20 dark:border-gray-700">
                        Wajib ganti password saat login berikutnya
                    </label>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" wire:click="cancelEdit" class="inline-flex h-9 items-center gap-1 rounded-md border border-stone-200 bg-white px-3 text-[11px] font-semibold uppercase tracking-wider text-stone-600 transition hover:border-ista-primary/30 hover:text-ista-primary dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        Batal
                    </button>
                    <button type="button" wire:click="saveEdit" class="inline-flex h-9 items-center gap-1 rounded-md bg-ista-primary px-3 text-[11px] font-semibold uppercase tracking-wider text-amber-300 transition hover:bg-stone-800">
                        Simpan Perubahan
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Deactivate confirmation --}}
    @if ($deactivatingUserId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/40 p-4 backdrop-blur-sm dark:bg-black/60" role="dialog" aria-modal="true">
            <div class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <h3 class="text-base font-semibold text-stone-900 dark:text-gray-100">Konfirmasi Nonaktifkan Akun</h3>
                <p class="mt-1 text-xs leading-relaxed text-stone-500 dark:text-gray-400">
                    Akun yang dinonaktifkan tidak dapat login ke admin meskipun masih memiliki sesi aktif.
                </p>

                <div class="mt-3">
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 dark:text-gray-400">Alasan (opsional)</label>
                    <textarea wire:model.defer="deactivateReason" rows="2" class="mt-1 admin-filter__control w-full" placeholder="Misal: rotasi tugas, mutasi"></textarea>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" wire:click="cancelDeactivate" class="inline-flex h-9 items-center gap-1 rounded-md border border-stone-200 bg-white px-3 text-[11px] font-semibold uppercase tracking-wider text-stone-600 transition hover:border-ista-primary/30 hover:text-ista-primary dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        Batal
                    </button>
                    <button type="button" wire:click="confirmDeactivate" class="inline-flex h-9 items-center gap-1 rounded-md bg-rose-600 px-3 text-[11px] font-semibold uppercase tracking-wider text-white transition hover:bg-rose-700">
                        Nonaktifkan
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Create modal --}}
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/40 p-4 backdrop-blur-sm dark:bg-black/60" role="dialog" aria-modal="true">
            <div class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <h3 class="text-base font-semibold text-stone-900 dark:text-gray-100">Tambah Akun Admin</h3>
                <p class="mt-1 text-xs text-stone-500 dark:text-gray-400">Buat akun admin atau super admin baru. Akun otomatis diverifikasi.</p>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 dark:text-gray-400">Nama</label>
                        <input type="text" wire:model.defer="newName" class="mt-1 admin-filter__control w-full">
                        @error('newName') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 dark:text-gray-400">Email</label>
                        <input type="email" wire:model.defer="newEmail" class="mt-1 admin-filter__control w-full" placeholder="email@instansi.go.id">
                        @error('newEmail') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 dark:text-gray-400">Password Sementara</label>
                        <div class="mt-1 flex gap-2">
                            <input type="text" wire:model.defer="newPassword" class="admin-filter__control w-full font-mono">
                            <button type="button" wire:click="generateNewPassword" class="inline-flex h-10 items-center rounded-md border border-stone-200 bg-white px-3 text-[11px] font-semibold uppercase tracking-wider text-stone-600 transition hover:border-ista-primary/30 hover:text-ista-primary dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                Generate
                            </button>
                        </div>
                        @error('newPassword') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-500 dark:text-gray-400">Role</label>
                        <select wire:model.defer="newRole" class="mt-1 admin-filter__control w-full">
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                        @error('newRole') <p class="mt-1 text-[11px] text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-xs text-stone-600 dark:text-gray-300">
                        <input type="checkbox" wire:model.defer="newForcePasswordChange" class="h-4 w-4 rounded border-stone-300 text-ista-primary focus:ring-ista-primary/20 dark:border-gray-700">
                        Wajibkan ganti password setelah login pertama
                    </label>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" wire:click="closeCreateModal" class="inline-flex h-9 items-center gap-1 rounded-md border border-stone-200 bg-white px-3 text-[11px] font-semibold uppercase tracking-wider text-stone-600 transition hover:border-ista-primary/30 hover:text-ista-primary dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                        Batal
                    </button>
                    <button type="button" wire:click="createAccount" class="inline-flex h-9 items-center gap-1 rounded-md bg-ista-primary px-3 text-[11px] font-semibold uppercase tracking-wider text-amber-300 transition hover:bg-stone-800">
                        Buat Akun
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
