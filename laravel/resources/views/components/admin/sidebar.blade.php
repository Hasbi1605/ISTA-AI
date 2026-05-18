@php
    $user = auth()->user();
    $items = [
        [
            'label' => 'Overview',
            'description' => 'Ringkasan dashboard',
            'route' => 'admin.dashboard',
            'icon' => 'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4a1 1 0 001-1v-5h2v5a1 1 0 001 1h4a1 1 0 001-1V10',
            'visible' => true,
        ],
        [
            'label' => 'Users',
            'description' => 'Presence & aktivitas',
            'route' => 'admin.users',
            'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-5.13a4 4 0 11-8 0 4 4 0 018 0zm6 0a4 4 0 11-8 0 4 4 0 018 0z',
            'visible' => true,
        ],
        [
            'label' => 'Usage',
            'description' => 'Event AI per fitur',
            'route' => 'admin.usage',
            'icon' => 'M9 19V6l12-3v13M9 19c0 1.657-1.343 3-3 3s-3-1.343-3-3 1.343-3 3-3 3 1.343 3 3zm12-3c0 1.657-1.343 3-3 3s-3-1.343-3-3 1.343-3 3-3 3 1.343 3 3z',
            'visible' => true,
        ],
        [
            'label' => 'Errors',
            'description' => 'Event gagal & blocked',
            'route' => 'admin.errors',
            'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 17c-.77 1.333.192 3 1.732 3z',
            'visible' => true,
        ],
        [
            'label' => 'Documents',
            'description' => 'Status dokumen user',
            'route' => 'admin.documents',
            'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
            'visible' => true,
        ],
    ];

    if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        $items[] = [
            'label' => 'AI Configuration',
            'description' => 'Hanya super admin',
            'route' => 'admin.ai-config',
            'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.998.61 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
            'visible' => true,
        ];
    }

    $roleLabel = match (true) {
        $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() => 'Super Admin',
        $user && method_exists($user, 'isAdmin') && $user->isAdmin() => 'Admin',
        default => 'Admin',
    };
@endphp

<div class="flex h-full flex-col">
    <div class="flex items-center justify-between gap-3 border-b border-stone-200/80 px-6 py-5 dark:border-gray-800">
        <a href="{{ route('admin.dashboard') }}" class="group flex items-center gap-3">
            <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-8 w-8 object-contain transition-transform duration-300 group-hover:rotate-6 group-hover:scale-110">
            <div class="ista-brand-title text-lg text-ista-primary not-italic">
                ISTA <span class="font-light italic text-ista-gold">AI</span>
            </div>
        </a>
        <button type="button"
                @click="sidebarOpen = false"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-stone-500 transition hover:bg-stone-100 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden"
                aria-label="Tutup sidebar admin">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Navigasi admin">
        <p class="px-3 pb-2 text-[10.5px] font-bold uppercase tracking-[0.16em] text-stone-400 dark:text-gray-500">Menu</p>
        <ul class="space-y-1" role="list">
            @foreach ($items as $item)
                @continue(! ($item['visible'] ?? false))
                @php
                    $active = request()->routeIs($item['route']);
                @endphp
                <li>
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'admin-nav-link group',
                           'admin-nav-link--active' => $active,
                       ])
                       @if ($active) aria-current="page" @endif>
                        <span class="admin-nav-link__icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $item['icon'] }}"/>
                            </svg>
                        </span>
                        <span class="flex-1">
                            <span class="block text-sm font-semibold leading-tight">{{ $item['label'] }}</span>
                            @if (! empty($item['description']))
                                <span class="mt-0.5 block text-[11px] font-medium text-stone-400 group-hover:text-ista-primary/60 dark:text-gray-500 dark:group-hover:text-amber-300/70">{{ $item['description'] }}</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="border-t border-stone-200/80 px-4 py-4 dark:border-gray-800">
        @auth
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ista-primary text-sm font-bold text-amber-300">
                    {{ substr(auth()->user()->name, 0, 1) }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-stone-800 dark:text-gray-100">{{ auth()->user()->name }}</p>
                    <p class="truncate text-[11px] font-medium uppercase tracking-wider text-stone-400 dark:text-gray-500">{{ $roleLabel }}</p>
                </div>
            </div>
        @endauth
    </div>
</div>
