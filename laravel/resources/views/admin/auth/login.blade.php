<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">

    <title>Admin Login · ISTA AI</title>

    <link rel="icon" type="image/png" href="{{ asset('images/ista/logo.png') }}">

    <script>
        (() => {
            const storedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const useDarkTheme = storedTheme ? storedTheme === 'dark' : prefersDark;

            document.documentElement.classList.toggle('dark', useDarkTheme);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/css/admin.css', 'resources/js/app.js'])
</head>
<body class="ista-shell ista-display-sans min-h-screen bg-[#fafaf9] text-stone-800 transition-colors duration-300 dark:bg-gray-950 dark:text-gray-100">
    <div class="relative flex min-h-screen w-full items-center justify-center overflow-hidden px-4 py-10">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute -top-40 -left-40 h-[26rem] w-[26rem] rounded-full bg-ista-primary/[0.08] blur-3xl dark:bg-ista-primary/10"></div>
            <div class="absolute -bottom-40 -right-32 h-[28rem] w-[28rem] rounded-full bg-ista-gold/[0.08] blur-3xl dark:bg-amber-500/10"></div>
        </div>

        <div class="absolute right-4 top-4 sm:right-6 sm:top-6">
            <button type="button"
                    data-admin-theme-toggle
                    class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-stone-200 bg-white text-stone-500 transition hover:border-ista-primary/30 hover:text-ista-primary dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-ista-primary/40 dark:hover:text-amber-300"
                    aria-pressed="false"
                    aria-label="Toggle dark mode">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v2.5M12 18.5V21M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M3 12h2.5M18.5 12H21M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8M12 16a4 4 0 100-8 4 4 0 000 8z" />
                </svg>
                <svg xmlns="http://www.w3.org/2000/svg" class="hidden h-5 w-5 dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" />
                </svg>
            </button>
        </div>

        <main class="relative w-full max-w-md">
            <div class="mb-6 flex flex-col items-center text-center">
                <a href="{{ route('admin.login') }}" class="inline-flex items-center justify-center">
                    <span class="ista-brand-title text-[1.9rem] leading-none text-ista-primary not-italic dark:text-amber-200">
                        ISTA <span class="font-light italic text-ista-gold dark:text-amber-300">AI</span>
                    </span>
                </a>
                <h1 class="mt-4 text-2xl font-semibold text-stone-900 dark:text-gray-50">Login Admin</h1>
                <p class="mt-2 max-w-sm text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Akses terbatas untuk administrator yang berwenang. Aktivitas login dicatat untuk keperluan audit.
                </p>
            </div>

            <section class="rounded-2xl border border-stone-200 bg-white/95 p-6 shadow-sm backdrop-blur dark:border-gray-800 dark:bg-gray-900/80 sm:p-8">
                @if ($errors->any())
                    <div role="alert" class="mb-5 rounded-lg border border-rose-200 bg-rose-50/80 p-3 text-sm text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
                        {{ $errors->first() }}
                    </div>
                @endif

                @if (session('status'))
                    <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50/80 p-3 text-sm text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.login.attempt') }}" class="space-y-4" novalidate>
                    @csrf

                    <div>
                        <label for="admin-email" class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:text-gray-400">Email</label>
                        <input id="admin-email"
                               type="email"
                               name="email"
                               value="{{ old('email') }}"
                               autocomplete="username"
                               required
                               class="mt-2 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm font-medium text-stone-800 shadow-[inset_0_1px_0_rgba(28,25,23,0.03)] transition placeholder:text-stone-400 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-500"
                               placeholder="admin@instansi.go.id">
                    </div>

                    <div>
                        <label for="admin-password" class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:text-gray-400">Password</label>
                        <input id="admin-password"
                               type="password"
                               name="password"
                               autocomplete="current-password"
                               required
                               class="mt-2 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm font-medium text-stone-800 shadow-[inset_0_1px_0_rgba(28,25,23,0.03)] transition placeholder:text-stone-400 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-500"
                               placeholder="••••••••">
                    </div>

                    <button type="submit"
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-ista-primary px-4 text-sm font-semibold uppercase tracking-wider text-amber-300 shadow-sm transition hover:bg-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-ista-primary/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 8l4 4m0 0l-4 4m4-4H5m4-8H7a2 2 0 00-2 2v12a2 2 0 002 2h2"/>
                        </svg>
                        Masuk ke Admin
                    </button>
                </form>

                <p class="mt-5 text-center text-[11px] uppercase tracking-[0.18em] text-stone-400 dark:text-gray-500">
                    Halaman terbatas. Bukan untuk pendaftaran publik.
                </p>
            </section>

            <p class="mt-6 text-center text-[11px] text-stone-400 dark:text-gray-500">
                © {{ date('Y') }} ISTA AI · Akses admin diawasi dan dicatat.
            </p>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggle = document.querySelector('[data-admin-theme-toggle]');

            if (! toggle) {
                return;
            }

            const syncToggleState = () => {
                toggle.setAttribute('aria-pressed', document.documentElement.classList.contains('dark') ? 'true' : 'false');
            };

            syncToggleState();

            toggle.addEventListener('click', () => {
                const useDarkTheme = ! document.documentElement.classList.contains('dark');

                document.documentElement.classList.toggle('dark', useDarkTheme);
                localStorage.setItem('theme', useDarkTheme ? 'dark' : 'light');
                syncToggleState();
            });
        });
    </script>
</body>
</html>
