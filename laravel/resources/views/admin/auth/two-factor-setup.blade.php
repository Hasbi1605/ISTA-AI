<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">

    <title>Aktivasi 2FA · ISTA AI</title>

    <link rel="icon" type="image/png" href="{{ asset('images/ista/logo.png') }}">

    <script>
        if (localStorage.theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    @vite(['resources/css/app.css', 'resources/css/admin.css', 'resources/js/app.js'])
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
                <p class="mt-3 text-[11px] font-semibold uppercase tracking-[0.22em] text-stone-400 dark:text-gray-500">Admin Console</p>
                <h1 class="mt-2 text-2xl font-semibold text-stone-900 dark:text-gray-50">Aktifkan Verifikasi 2 Langkah</h1>
                <p class="mt-2 max-w-sm text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    Demi keamanan, akun admin <strong class="font-semibold text-stone-700 dark:text-gray-200">wajib</strong> mengaktifkan verifikasi 2 langkah sebelum masuk ke dashboard.
                </p>
                <ol class="mt-3 max-w-sm space-y-1 text-left text-sm leading-relaxed text-stone-500 dark:text-gray-400">
                    <li>1. Pasang aplikasi <strong class="font-semibold text-stone-700 dark:text-gray-200">Google Authenticator</strong> atau <strong class="font-semibold text-stone-700 dark:text-gray-200">Authy</strong> di ponsel Anda.</li>
                    <li>2. Pindai (scan) kode QR di bawah ini dengan aplikasi tersebut.</li>
                    <li>3. Masukkan 6 angka yang muncul di aplikasi untuk mengaktifkan.</li>
                </ol>
            </div>

            <section class="rounded-2xl border border-stone-200 bg-white/95 p-6 shadow-sm backdrop-blur dark:border-gray-800 dark:bg-gray-900/80 sm:p-8">
                @if ($errors->any())
                    <div role="alert" class="mb-5 rounded-lg border border-rose-200 bg-rose-50/80 p-3 text-sm text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-200">
                        {{ $errors->first() }}
                    </div>
                @endif

                <div class="flex flex-col items-center" x-data="{ copied: false }">
                    <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-gray-700 dark:bg-white">
                        {!! $qrSvg !!}
                    </div>
                    <p class="mt-4 text-center text-[12px] text-stone-500 dark:text-gray-400">
                        Tidak bisa memindai? Masukkan kode ini secara manual di aplikasi Anda:
                    </p>
                    <div class="mt-2 flex items-center gap-2">
                        <code x-ref="secret" class="break-all rounded-md bg-stone-100 px-2 py-1 text-sm font-medium tracking-wide text-stone-700 dark:bg-gray-800 dark:text-gray-200">{{ $secret }}</code>
                        <button type="button"
                                @click="navigator.clipboard.writeText($refs.secret.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)"
                                class="inline-flex items-center gap-1 rounded-md border border-stone-300 px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-stone-500 transition hover:border-ista-primary/40 hover:text-ista-primary dark:border-gray-700 dark:text-gray-300"
                                aria-label="Salin kode">
                            <span x-show="!copied">Salin</span>
                            <span x-show="copied" x-cloak>Tersalin</span>
                        </button>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.2fa.confirm') }}" class="mt-6 space-y-4" novalidate>
                    @csrf
                    <div>
                        <label for="code" class="block text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:text-gray-400">Masukkan 6 angka dari aplikasi</label>
                        <input id="code"
                               type="text"
                               name="code"
                               inputmode="numeric"
                               autocomplete="one-time-code"
                               autofocus
                               required
                               class="mt-2 w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-center text-lg font-semibold tracking-[0.4em] text-stone-800 shadow-[inset_0_1px_0_rgba(28,25,23,0.03)] transition placeholder:tracking-normal placeholder:text-stone-400 focus:border-ista-primary focus:outline-none focus:ring-2 focus:ring-ista-primary/15 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:placeholder:text-gray-500"
                               placeholder="123456">
                    </div>

                    <button type="submit"
                            class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-ista-primary px-4 text-sm font-semibold uppercase tracking-wider text-amber-300 shadow-sm transition hover:bg-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-ista-primary/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">
                        Konfirmasi &amp; Aktifkan
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
