<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'ISTA AI') }} - Profil</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="ista-shell ista-display-sans text-stone-800">
        <div class="relative min-h-screen overflow-hidden bg-[#fafaf9]">
            <div class="absolute inset-0 z-0">
                <div class="h-full w-full animate-breathe bg-cover bg-center opacity-100" style="background-image: url('/images/ista/login-bg.png');"></div>
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(212,175,55,0.15)_0%,rgba(250,250,249,0.4)_80%)]"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-[#d4af37]/20 via-white/5 to-transparent"></div>
            </div>

            <div class="pointer-events-none absolute inset-0 z-0 overflow-hidden">
                <div class="animate-float-slow absolute left-1/4 top-1/4 h-64 w-64 cursor-pointer rounded-full bg-yellow-400/20 mix-blend-overlay blur-3xl"></div>
                <div class="animate-float-reverse absolute bottom-1/4 right-1/4 h-80 w-80 cursor-pointer rounded-full bg-rose-500/10 mix-blend-multiply blur-3xl"></div>

                <div class="animate-twinkle absolute left-20 top-10 h-2 w-2 cursor-pointer rounded-full bg-yellow-500 blur-[1px]" style="animation-delay: 0s"></div>
                <div class="animate-twinkle absolute bottom-20 right-10 h-3 w-3 cursor-pointer rounded-full bg-rose-400 blur-[2px]" style="animation-delay: 1s"></div>
                <div class="animate-twinkle absolute left-10 top-1/2 h-1.5 w-1.5 cursor-pointer rounded-full bg-white blur-[1px]" style="animation-delay: 2s"></div>
                <div class="animate-twinkle absolute right-1/3 top-20 h-2 w-2 cursor-pointer rounded-full bg-yellow-600/60 blur-[1px]" style="animation-delay: 1.5s"></div>
            </div>

            <header class="ista-navbar">
                <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-5 py-4 sm:px-10">
                    <a href="{{ route('dashboard') }}" class="group flex items-center gap-3">
                        <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-8 w-8 object-contain transition-transform duration-300 group-hover:rotate-6 group-hover:scale-110">
                        <div class="ista-brand-title text-xl text-[#8b0836] not-italic">ISTA <span class="font-light italic text-[#d4af37]">AI</span></div>
                    </a>
                    <div class="flex items-center gap-4 sm:gap-8">
                        <livewire:dashboard-nav-profile />
                    </div>
                </div>
            </header>

            <main class="relative z-10 mx-auto flex min-h-[calc(100vh-136px)] w-full max-w-[640px] flex-col items-center pt-8 pb-20 px-5 sm:px-10 font-sans">
                <div class="w-full" x-data="{ activeTab: 'profile' }">
                    <div class="group/card ista-glass-card">
                        <div class="absolute inset-0 z-0 -translate-x-[200%] bg-gradient-to-tr from-white/0 via-white/40 to-white/0 group-hover/card:animate-[shimmer_1s_ease-out]"></div>

                        <div class="relative z-20 px-10 pb-6 pt-8 text-center">
                            <h1 class="ista-brand-title mb-1 flex items-center justify-center gap-2 cursor-default text-4xl tracking-tight drop-shadow-sm transition-all duration-300 not-italic">
                                <span class="text-stone-900 not-italic">Pengaturan</span> <span class="text-[#8b0836] not-italic">Profil</span>
                            </h1>
                            <p class="cursor-default text-[13px] font-medium text-stone-600 opacity-90">Kelola informasi akun dan keamanan Anda.</p>
                        </div>

                        <!-- Tab Navigation -->
                        <div class="relative z-20 flex border-b border-white/30 px-4 mt-2">
                            <button @click="activeTab = 'profile'"
                                    :class="{ 'border-[#8b0836] text-[#8b0836]': activeTab === 'profile', 'border-transparent text-stone-500 hover:text-stone-700 hover:border-stone-300/50': activeTab !== 'profile' }"
                                    class="flex-1 border-b-2 px-4 py-3 text-sm font-bold transition-all duration-300 outline-none focus:outline-none focus:ring-0 focus:bg-transparent active:bg-transparent [-webkit-tap-highlight-color:transparent]">
                                Informasi Profil
                            </button>
                            <button @click="activeTab = 'password'"
                                    :class="{ 'border-[#8b0836] text-[#8b0836]': activeTab === 'password', 'border-transparent text-stone-500 hover:text-stone-700 hover:border-stone-300/50': activeTab !== 'password' }"
                                    class="flex-1 border-b-2 px-4 py-3 text-sm font-bold transition-all duration-300 outline-none focus:outline-none focus:ring-0 focus:bg-transparent active:bg-transparent [-webkit-tap-highlight-color:transparent]">
                                Ubah Kata Sandi
                            </button>
                            <button @click="activeTab = 'delete'"
                                    :class="{ 'border-[#8b0836] text-[#8b0836]': activeTab === 'delete', 'border-transparent text-stone-500 hover:text-stone-700 hover:border-stone-300/50': activeTab !== 'delete' }"
                                    class="flex-1 border-b-2 px-4 py-3 text-sm font-bold transition-all duration-300 outline-none focus:outline-none focus:ring-0 focus:bg-transparent active:bg-transparent [-webkit-tap-highlight-color:transparent]">
                                Hapus Akun
                            </button>
                        </div>

                        <!-- Tab Contents -->
                        <div class="relative z-20 p-6 sm:p-10 min-h-[420px]">
                            <!-- Profile Information Form -->
                            <div x-show="activeTab === 'profile'"
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-y-4"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 style="display: none;">
                                <livewire:profile.update-profile-information-form />
                            </div>

                            <!-- Update Password Form -->
                            <div x-show="activeTab === 'password'"
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-y-4"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 style="display: none;">
                                <livewire:profile.update-password-form />
                            </div>

                            <!-- Delete User Form -->
                            <div x-show="activeTab === 'delete'"
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-y-4"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 style="display: none;">
                                <livewire:profile.delete-user-form />
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
