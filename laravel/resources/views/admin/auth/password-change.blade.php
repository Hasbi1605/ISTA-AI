<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      x-data="{ darkMode: localStorage.getItem('theme') === 'dark' }"
      x-init="$watch('darkMode', value => { localStorage.setItem('theme', value ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', value); })"
      :class="{ 'dark': darkMode }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">

    <title>Ganti Password Admin · ISTA AI</title>

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
<body class="ista-shell ista-display-sans min-h-screen bg-[#fafaf9] text-stone-800 transition-colors duration-300 dark:bg-gray-950 dark:text-gray-100">
    <div class="relative flex min-h-screen w-full items-center justify-center overflow-hidden px-4 py-10">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute -top-40 -left-40 h-[26rem] w-[26rem] rounded-full bg-ista-primary/[0.08] blur-3xl dark:bg-ista-primary/10"></div>
            <div class="absolute -bottom-40 -right-32 h-[28rem] w-[28rem] rounded-full bg-ista-gold/[0.08] blur-3xl dark:bg-amber-500/10"></div>
        </div>

        <main class="relative w-full max-w-md">
            <div class="mb-6 flex flex-col items-center text-center">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3">
                    <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-10 w-10 object-contain">
                    <span class="ista-brand-title text-2xl text-ista-primary not-italic dark:text-amber-200">
                        ISTA <span class="font-light italic text-ista-gold dark:text-amber-300">AI</span>
                    </span>
                </a>
                <p class="mt-3 text-[11px] font-semibold uppercase tracking-[0.22em] text-stone-400 dark:text-gray-500">Admin Console</p>
                <h1 class="mt-2 text-2xl font-semibold text-stone-900 dark:text-gray-50">Wajib Ganti Password</h1>
                <p class="mt-2 max-w-sm text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Akun ini menggunakan password sementara. Buat password baru sebelum melanjutkan ke admin dashboard.
                </p>
            </div>

            <section class="rounded-2xl border border-stone-200 bg-white/95 p-6 shadow-sm backdrop-blur dark:border-gray-800 dark:bg-gray-900/80 sm:p-8">
                @if ($errors->any())
                    <div role="alert" class="mb-5 rounded-lg border border-rose-200 bg-rose-50/80 p-3 text-sm text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
                        <ul class="list-disc pl-5 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.password.update') }}" class="space-y-4" novalidate>
                    @csrf

                    <div>
                        <label for="current_password" class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:text-gray-400">Password Saat Ini</label>
                        <input id="current_password"
                               type="password"
                               name="current_password"
                               autocomplete="current-password"
                               required
                               class="mt-2 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm font-medium text-stone-800 shadow-[inset_0_1px_0_rgba(28,25,23,0.03)] transition placeholder:text-stone-400 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-500"
                               placeholder="Password sementara">
                    </div>

                    <div>
                        <label for="password" class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:text-gray-400">Password Baru</label>
                        <input id="password"
                               type="password"
                               name="password"
                               autocomplete="new-password"
                               required
                               class="mt-2 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm font-medium text-stone-800 shadow-[inset_0_1px_0_rgba(28,25,23,0.03)] transition placeholder:text-stone-400 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-500"
                               placeholder="Minimal 8 karakter">
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:text-gray-400">Konfirmasi Password Baru</label>
                        <input id="password_confirmation"
                               type="password"
                               name="password_confirmation"
                               autocomplete="new-password"
                               required
                               class="mt-2 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm font-medium text-stone-800 shadow-[inset_0_1px_0_rgba(28,25,23,0.03)] transition placeholder:text-stone-400 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-500"
                               placeholder="Ulangi password baru">
                    </div>

                    <button type="submit"
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-ista-primary px-4 text-sm font-semibold uppercase tracking-wider text-amber-300 shadow-sm transition hover:bg-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-ista-primary/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">
                        Simpan Password Baru
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.logout') }}" class="mt-4 text-center">
                    @csrf
                    <button type="submit" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400 transition hover:text-rose-600 dark:text-gray-500 dark:hover:text-rose-300">
                        Keluar
                    </button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>
