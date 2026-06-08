<div @class([
    'relative w-full max-w-[640px] p-6 font-sans transition-all duration-500',
    'opacity-30 blur-sm pointer-events-none' => $showVerificationModal,
]) style="{{ $showVerificationModal ? 'z-index: 0; filter: brightness(0.28) saturate(0.45);' : 'z-index: 10;' }}">
    <div class="group/card ista-glass-card">
        <div class="absolute inset-0 z-0 -translate-x-[200%] bg-gradient-to-tr from-white/0 via-white/40 to-white/0 group-hover/card:animate-[shimmer_1s_ease-out]"></div>
        <button type="button" @click="darkMode = !darkMode" :aria-pressed="darkMode ? 'true' : 'false'" class="absolute right-5 top-5 z-30 inline-flex h-10 w-10 items-center justify-center rounded-full border border-white/60 bg-white/70 text-stone-600 shadow-sm backdrop-blur-md transition hover:text-ista-primary dark:border-gray-700 dark:bg-gray-900/70 dark:text-gray-300 dark:hover:text-amber-300" aria-label="Toggle dark mode">
            <svg x-show="!darkMode" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v2.5M12 18.5V21M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M3 12h2.5M18.5 12H21M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8M12 16a4 4 0 100-8 4 4 0 000 8z" />
            </svg>
            <svg x-show="darkMode" style="display: none;" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" />
            </svg>
        </button>

        <div class="animate-enter-1 relative z-20 px-10 pb-6 pt-8 text-center">
            <div class="group/logo mb-4 inline-flex h-16 w-16 items-center justify-center rounded-2xl border border-white/40 bg-white/80 shadow-sm transition-transform duration-500 hover:scale-110 hover:rotate-6">
                <img src="{{ asset('images/ista/logo.png') }}" class="h-9 w-9 object-contain group-hover/logo:brightness-110" alt="Logo">
            </div>

            <h1 class="ista-brand-title mb-1 flex items-center justify-center gap-2 text-4xl tracking-tight drop-shadow-sm transition-all duration-300 not-italic">
                <span class="text-stone-900 not-italic">Login</span> <span class="text-ista-primary not-italic">ISTA <span class="font-light italic text-ista-gold">AI</span></span>
            </h1>
            <p class="text-[13px] font-medium text-stone-600 opacity-90">Asisten Istana Pintar</p>
        </div>

        <div class="relative z-20 px-12 pb-10">
            <x-auth-session-status class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700" :status="session('status')" />

            @if($view === 'login' || ($view === 'register' && ! config('auth.registration.enabled')))
                @include('livewire.pages.auth.partials.login-form')

                <div class="animate-enter-4 mt-6 text-center">
                    <a href="{{ url('/') }}" class="group inline-flex items-center gap-2 text-xs font-bold text-rose-900 transition-colors hover:text-amber-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform duration-300 group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span>Kembali ke Beranda</span>
                    </a>
                </div>
            @elseif($view === 'register' && config('auth.registration.enabled'))
                @include('livewire.pages.auth.partials.register-form')
                <div class="animate-enter-4 mt-6 text-center">
                    <a href="{{ url('/') }}" class="group inline-flex items-center gap-2 text-xs font-bold text-rose-900 transition-colors hover:text-amber-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform duration-300 group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span>Kembali ke Beranda</span>
                    </a>
                </div>
            @elseif($view === 'forgot-password')
                @include('livewire.pages.auth.partials.forgot-password-form')
                <div class="animate-enter-4 mt-6 text-center">
                    <a href="{{ url('/') }}" class="group inline-flex items-center gap-2 text-xs font-bold text-rose-900 transition-colors hover:text-amber-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform duration-300 group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span>Kembali ke Beranda</span>
                    </a>
                </div>
            @endif

            <div class="animate-enter-4 mt-8 border-t border-rose-900/10 pt-4 text-center">
                <p class="inline-block text-[12px] font-semibold text-rose-950/60 transition-colors hover:scale-105 hover:text-rose-900">
                    Copyright © 2026 Istana Kepresidenan Yogyakarta, All rights reserved.
                </p>
            </div>
        </div>
    </div>
</div>
