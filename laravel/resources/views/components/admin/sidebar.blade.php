@php
    $user = auth()->user();
    $monitoringItems = [
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
            'icon' => 'M4 19V10m5 9V5m5 14v-7m5 7V8M3 19h18',
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

    $administrationItems = [];

    if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
        $administrationItems[] = [
            'label' => 'Account Management',
            'description' => 'Kelola akun admin',
            'route' => 'admin.accounts',
            'icon' => 'M12 11c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v3h16v-3c0-2.66-5.33-4-8-4z',
            'visible' => true,
        ];

        $administrationItems[] = [
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
            <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-9 w-9 object-contain transition-transform duration-300 group-hover:rotate-6 group-hover:scale-105">
            <div class="ista-brand-title text-[1.15rem] text-ista-primary not-italic">
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

    <nav class="flex-1 overflow-y-auto px-4 py-5" aria-label="Navigasi admin">
        <div class="admin-sidebar-group">
            <p class="admin-sidebar-heading">Monitoring</p>
            <ul class="space-y-2" role="list">
                @foreach ($monitoringItems as $item)
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
                                    <span class="sr-only">{{ $item['description'] }}</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        @if (! empty($administrationItems))
            <div class="admin-sidebar-group mt-6 border-t border-stone-200/80 pt-5 dark:border-gray-800">
                <p class="admin-sidebar-heading">Administration</p>
                <ul class="space-y-2" role="list">
                    @foreach ($administrationItems as $item)
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
                                        <span class="sr-only">{{ $item['description'] }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </nav>

    <div class="relative border-t border-stone-200/80 px-6 py-4 dark:border-gray-800"
         x-data="{ open: false }"
         @click.outside="open = false"
         @keydown.escape.window="open = false">
        @auth
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="translate-y-1 opacity-0"
                 x-transition:enter-end="translate-y-0 opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="translate-y-0 opacity-100"
                 x-transition:leave-end="translate-y-1 opacity-0"
                 id="admin-sidebar-profile-menu"
                 class="absolute bottom-full left-4 right-4 z-50 mb-2 overflow-hidden rounded-xl border border-stone-100 bg-white py-1 shadow-xl ring-1 ring-black/5 dark:border-gray-800 dark:bg-gray-900"
                 role="menu"
                 style="display: none;">
                <a href="{{ route('admin.dashboard') }}"
                   class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-ista-primary transition hover:bg-stone-50 dark:text-amber-300 dark:hover:bg-gray-800"
                   role="menuitem"
                   @click="open = false">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    Dashboard Admin
                </a>
                <a href="{{ route('profile') }}"
                   class="block px-4 py-2.5 text-sm text-stone-700 transition hover:bg-stone-50 hover:text-ista-primary dark:text-gray-200 dark:hover:bg-gray-800 dark:hover:text-amber-300"
                   role="menuitem"
                   @click="open = false">
                    Profil
                </a>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit"
                            class="block w-full border-t border-stone-100 px-4 py-2.5 text-left text-sm text-stone-700 transition hover:bg-stone-50 hover:text-ista-primary dark:border-gray-800 dark:text-gray-200 dark:hover:bg-gray-800 dark:hover:text-amber-300"
                            role="menuitem">
                        Keluar
                    </button>
                </form>
            </div>

            <button type="button"
                    class="-mx-2 flex w-[calc(100%+1rem)] items-center gap-3 rounded-xl px-2 py-2 text-left transition hover:bg-stone-100/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-ista-primary/30 dark:hover:bg-gray-900"
                    aria-haspopup="menu"
                    aria-controls="admin-sidebar-profile-menu"
                    :aria-expanded="open ? 'true' : 'false'"
                    @click="open = ! open">
                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-ista-primary text-sm font-bold text-amber-300 shadow-sm">
                    {{ substr(auth()->user()->name, 0, 1) }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-stone-800 dark:text-gray-100">{{ auth()->user()->name }}</p>
                    <p class="truncate text-[11px] font-medium uppercase tracking-wider text-stone-400 dark:text-gray-500">{{ $roleLabel }}</p>
                </div>
                <svg class="h-4 w-4 text-stone-900 transition dark:text-gray-200"
                     :class="open ? 'rotate-180' : 'rotate-0'"
                     fill="none"
                     viewBox="0 0 24 24"
                     stroke="currentColor"
                     aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
        @endauth
    </div>
</div>
