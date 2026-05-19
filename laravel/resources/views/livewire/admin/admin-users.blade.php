@php
    $formatInt = fn ($value): string => number_format((float) $value, 0, ',', '.');
    $formatPct = function (int $value, int $total): string {
        if ($total <= 0) {
            return '0%';
        }

        return ((int) round(($value / $total) * 100)) . '%';
    };

    $totalUsers = (int) ($presenceSummary['total'] ?? 0);
    $onlineUsers = (int) ($presenceSummary['online'] ?? 0);
    $idleUsers = (int) ($presenceSummary['idle'] ?? 0);
    $offlineUsers = (int) ($presenceSummary['offline'] ?? 0);

    $presenceCards = [
        [
            'label' => 'Total User',
            'value' => $totalUsers,
            'description' => 'Semua user terdaftar',
            'tone' => 'primary',
            'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-5.13a4 4 0 11-8 0 4 4 0 018 0zm6 0a4 4 0 11-8 0 4 4 0 018 0z',
        ],
        [
            'label' => 'Online',
            'value' => $onlineUsers,
            'description' => $formatPct($onlineUsers, $totalUsers) . ' dari total user',
            'tone' => 'success',
            'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Idle',
            'value' => $idleUsers,
            'description' => $formatPct($idleUsers, $totalUsers) . ' dari total user',
            'tone' => 'warning',
            'icon' => 'M12 6v6l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'label' => 'Offline',
            'value' => $offlineUsers,
            'description' => $formatPct($offlineUsers, $totalUsers) . ' dari total user',
            'tone' => 'neutral',
            'icon' => 'M18.364 18.364A9 9 0 015.636 5.636m12.728 12.728A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636',
        ],
    ];

    $avatarInitials = function ($user): string {
        $name = trim((string) ($user->name ?: $user->email ?: '?'));

        if ($name === '') {
            return '?';
        }

        if ($user->role === \App\Models\User::ROLE_SUPER_ADMIN) {
            return strtoupper(substr($name, 0, 1));
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
        }

        return strtoupper(substr($name, 0, 2));
    };

@endphp

<div class="admin-users-page" wire:poll.30s>
    <div class="admin-users-hero">
        <div class="max-w-2xl">
            <p class="admin-users-eyebrow">Monitoring</p>
            <h2 class="admin-users-title">
                User <span>&amp;</span> Presence
            </h2>
            <p class="admin-users-description">
                Ringkasan status user tanpa membuka isi percakapan, dokumen, atau memo.
            </p>
        </div>
        <x-admin.badge tone="neutral" class="admin-users-readonly">Read-only</x-admin.badge>
    </div>

    <div class="admin-users-kpi-grid">
        @foreach ($presenceCards as $card)
            <article class="admin-users-kpi-card admin-users-kpi-card--{{ $card['tone'] }}">
                <div class="admin-users-kpi-card__header">
                    <span class="admin-users-kpi-card__icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $card['icon'] }}"/>
                        </svg>
                    </span>
                    <p class="admin-users-kpi-card__label">{{ $card['label'] }}</p>
                </div>
                <div class="admin-users-kpi-card__body">
                    <strong>{{ $formatInt($card['value']) }}</strong>
                    <p class="admin-users-kpi-card__description">{{ $card['description'] }}</p>
                </div>
            </article>
        @endforeach
    </div>

    <section class="admin-users-filter-panel admin-section">
        <div class="admin-users-filter-panel__header">
            <h3>Filter</h3>
            <div class="admin-users-reset-group">
                <button type="button" wire:click="resetFilters" class="admin-users-reset-button">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M4 4v6h6M20 20v-6h-6M5.5 14a7 7 0 0012 3M18.5 10a7 7 0 00-12-3"/>
                    </svg>
                    Reset
                </button>
            </div>
        </div>

        @if ($flashMessage)
            <div class="admin-users-alert admin-users-alert--success">
                <span>{{ $flashMessage }}</span>
                <button type="button" wire:click="clearFlash" aria-label="Tutup pesan">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M6 6l12 12M18 6L6 18"/>
                    </svg>
                </button>
            </div>
        @endif

        <div class="admin-users-filter-grid admin-users-filter-grid--compact">
            <label class="admin-users-filter">
                <span>Cari</span>
                <div class="admin-users-search-control">
                    <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-4.35-4.35m1.1-5.15a6.25 6.25 0 11-12.5 0 6.25 6.25 0 0112.5 0z" />
                    </svg>
                    <input type="search"
                           wire:model.live.debounce.300ms="search"
                           placeholder="Nama atau email..."
                           class="admin-users-control admin-users-control--search" />
                </div>
            </label>

            <label class="admin-users-filter">
                <span>Status</span>
                <select wire:model.live="status" class="admin-users-control">
                    <option value="">Semua</option>
                    @foreach ($statusOptions as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </section>

    <section class="admin-users-table-panel admin-section">
        <header class="admin-users-table-panel__header">
            <div>
                <h3>Daftar User</h3>
                <p>Menampilkan {{ $usersPerPage }} user per halaman.</p>
            </div>
        </header>

        <div class="admin-users-table-panel__body">
            @if ($users->isEmpty())
                <x-admin.empty-state
                    title="Tidak ada user"
                    description="Tidak ada user yang cocok dengan filter saat ini." />
            @else
                <x-admin.table
                    class="admin-users-table"
                    :columns="[
                        ['key' => 'user', 'label' => 'User', 'width' => '30%'],
                        ['key' => 'status', 'label' => 'Status', 'width' => '13%'],
                        ['key' => 'last_seen', 'label' => 'Last Seen', 'width' => '15%'],
                        ['key' => 'last_feature', 'label' => 'Aktivitas Terakhir', 'width' => '20%'],
                        ['key' => 'totals', 'label' => 'Total', 'align' => 'right', 'width' => '13%'],
                        ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right', 'width' => '9%'],
                    ]">
                    @foreach ($users as $user)
                        @php
                            $statusKey = $user->getAttribute('presence_status') ?? 'offline';
                            $statusTone = match ($statusKey) {
                                'online' => 'success',
                                'idle' => 'warning',
                                default => 'neutral',
                            };
                            $lastFeature = $user->last_active_feature
                                ? strtoupper(str_replace('_', '.', $user->last_active_feature))
                                : '—';
                        @endphp
                        <tr class="admin-users-table-row">
                            <td class="admin-table__td">
                                <div class="admin-users-user-cell">
                                    <span class="admin-user-avatar" aria-hidden="true">{{ $avatarInitials($user) }}</span>
                                    <div class="min-w-0">
                                        <span class="admin-users-user-cell__name">{{ $user->name }}</span>
                                        <span class="admin-users-user-cell__email">{{ $user->email }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="admin-table__td">
                                <x-admin.badge :tone="$statusTone" class="admin-users-status-badge">
                                    <span @class([
                                        'admin-users-status-dot',
                                        'admin-users-status-dot--online' => $statusKey === 'online',
                                        'admin-users-status-dot--idle' => $statusKey === 'idle',
                                        'admin-users-status-dot--offline' => $statusKey === 'offline',
                                    ])></span>
                                    {{ ucfirst($statusKey) }}
                                </x-admin.badge>
                            </td>
                            <td class="admin-table__td">
                                <span class="admin-users-muted" title="{{ $user->last_seen_at?->toDateTimeString() }}">
                                    {{ $user->last_seen_at ? $user->last_seen_at->diffForHumans() : 'Belum pernah' }}
                                </span>
                            </td>
                            <td class="admin-table__td">
                                <span class="admin-users-feature">{{ $lastFeature }}</span>
                            </td>
                            <td class="admin-table__td" data-align="right">
                                <div class="admin-users-total-cell">
                                    <span class="admin-users-total-cell__primary">{{ number_format($user->getAttribute('conversation_count') ?? 0) }} conv</span>
                                    <span class="admin-users-total-cell__meta">{{ number_format($user->getAttribute('document_count') ?? 0) }} doc</span>
                                    <span class="admin-users-total-cell__meta">{{ number_format($user->getAttribute('memo_count') ?? 0) }} memo</span>
                                </div>
                            </td>
                            <td class="admin-table__td" data-align="right">
                                @if ($canDeleteUsers)
                                    <button type="button"
                                            wire:click="deleteUser({{ $user->id }})"
                                            wire:confirm="Hapus akun user {{ $user->email }}? Percakapan, memo, dokumen, file, dan vector dokumen milik user ini akan ikut dibersihkan."
                                            class="admin-users-delete-button">
                                        Delete
                                    </button>
                                @else
                                    <span class="admin-users-muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>

                @if ($users->hasPages())
                    <div class="admin-users-pagination">
                        {{ $users->links('admin.pagination') }}
                    </div>
                @endif
            @endif
        </div>
    </section>
</div>
