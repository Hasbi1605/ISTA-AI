<div>
    <div class="mb-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="max-w-2xl">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">Monitoring</p>
                <h2 class="admin-page-title mt-1">User & Presence</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Pantau status online/idle/offline user dan ringkasan aktivitas mereka. Halaman ini tidak menampilkan isi percakapan, dokumen, atau memo.
                </p>
            </div>
            <x-admin.badge tone="neutral">Read-only</x-admin.badge>
        </div>
    </div>

    <x-admin.section title="Filter">
        <x-slot name="actions">
            <button type="button" wire:click="resetFilters" class="text-[11px] font-semibold uppercase tracking-wider text-stone-500 transition hover:text-ista-primary dark:text-gray-400 dark:hover:text-amber-300">
                Reset
            </button>
        </x-slot>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="admin-filter">
                <span class="admin-filter__label">Cari</span>
                <input type="search"
                       wire:model.live.debounce.300ms="search"
                       placeholder="Nama atau email…"
                       class="admin-filter__control" />
            </label>

            <label class="admin-filter">
                <span class="admin-filter__label">Status</span>
                <select wire:model.live="status" class="admin-filter__control">
                    <option value="">Semua</option>
                    @foreach ($statusOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="admin-filter">
                <span class="admin-filter__label">Role</span>
                <select wire:model.live="role" class="admin-filter__control">
                    <option value="">Semua</option>
                    @foreach ($roleOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </x-admin.section>

    <div class="mt-6">
        <x-admin.section
            title="Daftar User"
            description="Maksimum {{ \App\Services\Admin\AdminMetricsService::RECENT_ROWS_LIMIT }} baris ditampilkan.">
            @if ($users->isEmpty())
                <x-admin.empty-state
                    title="Tidak ada user"
                    description="Tidak ada user yang cocok dengan filter saat ini." />
            @else
                <x-admin.table :columns="[
                    ['key' => 'user', 'label' => 'User'],
                    ['key' => 'role', 'label' => 'Role'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'last_seen', 'label' => 'Last Seen'],
                    ['key' => 'last_feature', 'label' => 'Aktivitas Terakhir'],
                    ['key' => 'events_today', 'label' => 'Event Hari Ini', 'align' => 'right'],
                    ['key' => 'events_week', 'label' => 'Event 7 Hari', 'align' => 'right'],
                    ['key' => 'totals', 'label' => 'Total', 'align' => 'right'],
                ]">
                    @foreach ($users as $user)
                        @php
                            $statusKey = $user->getAttribute('presence_status') ?? 'offline';
                            $statusTone = match ($statusKey) {
                                'online' => 'success',
                                'idle' => 'warning',
                                default => 'neutral',
                            };
                        @endphp
                        <tr>
                            <td class="admin-table__td">
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold text-stone-700 dark:text-gray-200">{{ $user->name }}</span>
                                    <span class="text-[11px] text-stone-400 dark:text-gray-500">{{ $user->email }}</span>
                                </div>
                            </td>
                            <td class="admin-table__td">
                                <x-admin.badge :tone="$user->role === 'super_admin' ? 'gold' : ($user->role === 'admin' ? 'primary' : 'neutral')">
                                    {{ $roleOptions[$user->role] ?? $user->role }}
                                </x-admin.badge>
                            </td>
                            <td class="admin-table__td">
                                <x-admin.badge :tone="$statusTone">
                                    <span @class([
                                        'h-1.5 w-1.5 rounded-full',
                                        'bg-emerald-500' => $statusKey === 'online',
                                        'bg-amber-500' => $statusKey === 'idle',
                                        'bg-stone-400' => $statusKey === 'offline',
                                    ])></span>
                                    {{ ucfirst($statusKey) }}
                                </x-admin.badge>
                            </td>
                            <td class="admin-table__td">
                                <span class="text-xs text-stone-500 dark:text-gray-400" title="{{ $user->last_seen_at?->toDateTimeString() }}">
                                    {{ $user->last_seen_at ? $user->last_seen_at->diffForHumans() : 'Belum pernah' }}
                                </span>
                            </td>
                            <td class="admin-table__td">
                                <span class="font-mono text-[11px] uppercase tracking-wider text-stone-500 dark:text-gray-400">{{ $user->last_active_feature ?? '—' }}</span>
                            </td>
                            <td class="admin-table__td" data-align="right">
                                <span class="font-mono text-xs text-stone-700 dark:text-gray-200">{{ number_format($user->getAttribute('events_today') ?? 0) }}</span>
                            </td>
                            <td class="admin-table__td" data-align="right">
                                <span class="font-mono text-xs text-stone-500 dark:text-gray-400">{{ number_format($user->getAttribute('events_week') ?? 0) }}</span>
                            </td>
                            <td class="admin-table__td" data-align="right">
                                <div class="flex flex-col items-end text-[11px] text-stone-500 dark:text-gray-400">
                                    <span>{{ number_format($user->getAttribute('conversation_count') ?? 0) }} conv</span>
                                    <span>{{ number_format($user->getAttribute('document_count') ?? 0) }} doc · {{ number_format($user->getAttribute('memo_count') ?? 0) }} memo</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            @endif
        </x-admin.section>
    </div>
</div>
