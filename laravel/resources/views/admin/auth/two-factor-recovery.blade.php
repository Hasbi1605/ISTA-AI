<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">

    <title>Recovery Codes 2FA · ISTA AI</title>

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
                <span class="ista-brand-title text-[1.9rem] leading-none text-ista-primary not-italic dark:text-amber-200">
                    ISTA <span class="font-light italic text-ista-gold dark:text-amber-300">AI</span>
                </span>
                <h1 class="mt-4 text-2xl font-semibold text-stone-900 dark:text-gray-50">Simpan Recovery Codes</h1>
                <p class="mt-2 max-w-sm text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    2FA berhasil diaktifkan. Simpan kode pemulihan ini di tempat aman. Setiap kode hanya dapat dipakai sekali bila Anda kehilangan akses ke aplikasi authenticator.
                </p>
            </div>

            <section class="rounded-2xl border border-stone-200 bg-white/95 p-6 shadow-sm backdrop-blur dark:border-gray-800 dark:bg-gray-900/80 sm:p-8">
                <div class="rounded-lg border border-amber-200 bg-amber-50/70 p-3 text-[12px] text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                    Kode ini tidak akan ditampilkan lagi. Pastikan Anda menyalinnya sekarang.
                </div>

                <ul class="mt-4 grid grid-cols-2 gap-2">
                    @foreach ($recoveryCodes as $code)
                        <li class="rounded-md bg-stone-100 px-3 py-2 text-center font-mono text-sm tracking-wider text-stone-700 dark:bg-gray-800 dark:text-gray-200">{{ $code }}</li>
                    @endforeach
                </ul>

                <a href="{{ route('admin.dashboard') }}"
                   class="mt-6 inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-ista-primary px-4 text-sm font-semibold uppercase tracking-wider text-amber-300 shadow-sm transition hover:bg-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-ista-primary/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">
                    Saya sudah menyimpan, lanjut ke Admin
                </a>
            </section>
        </main>
    </div>
</body>
</html>
