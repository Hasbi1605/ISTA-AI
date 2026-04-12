<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'ISTA AI') }} - Dashboard</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="ista-shell ista-display-sans text-stone-800">
        <div class="relative min-h-screen overflow-hidden" style="background-image: radial-gradient(circle at 0 0, rgba(245, 158, 11, 0.08) 0%, rgba(245, 158, 11, 0) 30%), radial-gradient(circle at 100% 100%, rgba(76, 5, 25, 0.08) 0%, rgba(76, 5, 25, 0) 35%), url('{{ asset('images/ista/dashboard-grid.png') }}'); background-size: auto, auto, 8px 8px;">
            <div class="pointer-events-none absolute -left-20 -top-20 h-[28rem] w-[28rem] rounded-full bg-amber-100/50 blur-3xl"></div>
            <div class="pointer-events-none absolute -right-24 top-32 h-[24rem] w-[24rem] rounded-full bg-rose-100/60 blur-3xl"></div>

            <header class="ista-navbar">
                <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-5 py-4 sm:px-10">
                    <a href="{{ route('dashboard') }}" class="group flex items-center gap-3">
                        <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-8 w-8 object-contain transition-transform duration-300 group-hover:rotate-6 group-hover:scale-110">
                        <div class="ista-brand-title text-xl text-ista-primary not-italic">ISTA <span class="font-light italic text-ista-gold">AI</span></div>
                    </a>
                    <div class="flex items-center gap-4 sm:gap-8">
                        <livewire:dashboard-nav-profile />
                    </div>
                </div>
            </header>

            <div class="pointer-events-none absolute inset-0 overflow-hidden opacity-40">
                <div class="absolute left-1/4 top-10 h-32 w-32 animate-float rounded-full border border-amber-500/20"></div>
                <div class="absolute bottom-10 right-1/4 h-40 w-40 rounded-full border-[20px] border-ista-primary/5"></div>
                <div class="absolute right-12 top-1/2 h-3 w-3 animate-ping rounded-full bg-amber-400"></div>
                <div class="absolute left-10 top-1/3 grid grid-cols-2 gap-3">
                    <div class="h-1 w-1 rounded-full bg-stone-300"></div>
                    <div class="h-1 w-1 rounded-full bg-stone-300"></div>
                    <div class="h-1 w-1 rounded-full bg-stone-300"></div>
                    <div class="h-1 w-1 rounded-full bg-stone-300"></div>
                </div>
                <svg class="absolute bottom-12 left-1/3 h-32 w-32 -rotate-12 text-ista-primary/5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                </svg>
            </div>

            <main class="relative z-10 mx-auto flex min-h-[calc(100vh-136px)] w-full max-w-7xl items-center px-5 pb-20 pt-16 sm:px-10">
                <section class="w-full text-center">
                    <div class="mx-auto w-fit ista-pill">Asisten Istana Pintar</div>
                    <h1 class="ista-hero-title mt-8 text-stone-900">Tanya <strong><span class="text-ista-primary">ISTA</span> <span class="font-light italic text-ista-gold">AI</span></strong></h1>

                    @auth
                    <form action="{{ route('chat') }}" method="GET" class="ista-search-shell">
                        <input name="q" class="ista-search-input" type="text" placeholder="Tanya apapun..." required>
                        <button type="submit" class="ista-primary-cta">Mulai bertanya</button>
                    </form>
                    @else
                    <form action="{{ route('guest-chat') }}" method="GET" class="ista-search-shell">
                        <input name="q" class="ista-search-input" type="text" placeholder="Tanya apapun..." required>
                        <button type="submit" class="ista-primary-cta">Mulai bertanya</button>
                    </form>
                    @endauth

                    <div class="mx-auto mt-10 grid max-w-sm gap-3">
                        @auth
                        <a href="{{ route('chat') }}" class="rounded-2xl border border-stone-200 bg-white/80 px-4 py-3 text-sm font-semibold text-stone-700 transition hover:border-ista-primary/30 hover:text-ista-primary">Buka Chat</a>
                        @else
                        <a href="{{ route('guest-chat') }}" class="rounded-2xl border border-stone-200 bg-white/80 px-4 py-3 text-sm font-semibold text-stone-700 transition hover:border-ista-primary/30 hover:text-ista-primary">Buka Chat</a>
                        @endauth
                    </div>
                </section>
            </main>

            <footer class="relative z-20 flex h-[72px] flex-col items-center justify-center border-t border-stone-200/80 bg-white/80 backdrop-blur">
                <p class="text-[11px] font-bold uppercase tracking-widest text-[#78716c]">Copyright &copy; 2026 Istana Kepresidenan Yogyakarta</p>
                <p class="mt-1 text-[9px] font-medium uppercase tracking-[0.3em] text-[#a8a29e]">All Rights Reserved.</p>
            </footer>
        </div>
    </body>
</html>
