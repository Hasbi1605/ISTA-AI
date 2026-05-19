<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      x-data="{ darkMode: localStorage.getItem('theme') === 'dark', sidebarOpen: false }"
      x-init="document.documentElement.classList.toggle('dark', darkMode); $watch('darkMode', value => { localStorage.setItem('theme', value ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', value); })"
      :class="{ 'dark': darkMode }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Admin' }} · ISTA AI</title>

    <link rel="icon" type="image/png" href="{{ asset('images/ista/logo.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script>
        if (localStorage.theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ista-shell ista-display-sans overflow-hidden text-stone-800 dark:text-gray-100 transition-colors duration-300">
    <x-page-loader />

    <div class="admin-shell relative flex min-h-[var(--app-viewport-height)] w-full">
        <!-- Mobile sidebar backdrop -->
        <div x-show="sidebarOpen"
             x-transition.opacity
             @click="sidebarOpen = false"
             class="fixed inset-0 z-30 bg-stone-900/40 backdrop-blur-sm dark:bg-black/60 lg:hidden"
             style="display: none;"
             aria-hidden="true"></div>

        <!-- Sidebar -->
        <aside class="admin-sidebar"
               :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
               aria-label="Navigasi admin">
            <x-admin.sidebar />
        </aside>

        <!-- Main column -->
        <div class="admin-content">
            <header class="admin-topbar">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button"
                            @click="sidebarOpen = true"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-stone-500 transition hover:bg-stone-100 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden"
                            aria-label="Buka sidebar admin"
                            :aria-expanded="sidebarOpen ? 'true' : 'false'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div class="admin-breadcrumb min-w-0">
                        <a href="{{ route('admin.dashboard') }}" class="truncate text-stone-500 transition hover:text-ista-primary dark:text-gray-400 dark:hover:text-amber-300">Dashboard Admin</a>
                        <span class="text-stone-300 dark:text-gray-700">/</span>
                        <h1 class="truncate text-sm font-bold text-stone-950 dark:text-gray-100">
                            {{ $heading ?? ($title ?? 'Admin') }}
                        </h1>
                    </div>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    <button type="button"
                            @click="darkMode = !darkMode"
                            class="admin-icon-button"
                            :aria-pressed="darkMode ? 'true' : 'false'"
                            aria-label="Toggle dark mode">
                        <svg x-show="darkMode === false" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v2.5M12 18.5V21M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M3 12h2.5M18.5 12H21M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8M12 16a4 4 0 100-8 4 4 0 000 8z" />
                        </svg>
                        <svg x-show="darkMode === true" style="display: none;" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" />
                        </svg>
                    </button>
                    <a href="{{ route('chat') }}"
                       class="admin-action-button hidden sm:inline-flex">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12h18m0 0l-6-6m6 6l-6 6"/>
                        </svg>
                        Masuk ke Chat
                    </a>
                </div>
            </header>

            <main class="admin-main">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
