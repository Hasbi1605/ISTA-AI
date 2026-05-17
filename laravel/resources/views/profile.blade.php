<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="{ darkMode: localStorage.getItem('theme') === 'dark' }" x-init="$watch('darkMode', value => { localStorage.setItem('theme', value ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', value); }); document.documentElement.classList.toggle('dark', darkMode);" :class="{ 'dark': darkMode }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>ISTA AI</title>

        <link rel="icon" type="image/png" href="{{ asset('images/ista/logo.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        <script>
            if (localStorage.getItem('theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        </script>
        @vite(['resources/css/app.css', 'resources/css/auth.css', 'resources/js/app.js'])
    </head>
    <body class="ista-shell ista-display-sans text-stone-800 dark:text-gray-100">
        <x-page-loader />
        <div class="relative min-h-screen overflow-hidden bg-[#fafaf9] transition-colors duration-300 dark:bg-gray-950">
            <div class="absolute inset-0 z-0">
                <div class="h-full w-full animate-breathe bg-cover bg-center opacity-100" style="background-image: url('/images/ista/login-bg.png');"></div>
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(212,175,55,0.15)_0%,rgba(250,250,249,0.4)_80%)] dark:bg-[radial-gradient(circle_at_center,rgba(212,175,55,0.10)_0%,rgba(15,23,42,0.72)_80%)]"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-ista-gold/20 via-white/5 to-transparent dark:from-black/45 dark:via-gray-950/25"></div>
            </div>

            <div class="pointer-events-none absolute inset-0 z-0 overflow-hidden">
                <div class="animate-float-slow absolute left-1/4 top-1/4 h-64 w-64 rounded-full bg-yellow-400/20 mix-blend-overlay blur-3xl"></div>
                    <div class="animate-float-reverse absolute bottom-1/4 right-1/4 h-80 w-80 rounded-full bg-rose-500/10 mix-blend-multiply blur-3xl dark:mix-blend-screen"></div>

                <div class="animate-twinkle absolute left-20 top-10 h-2 w-2 rounded-full bg-yellow-500 blur-[1px]" style="animation-delay: 0s"></div>
                <div class="animate-twinkle absolute bottom-20 right-10 h-3 w-3 rounded-full bg-rose-400 blur-[2px]" style="animation-delay: 1s"></div>
                <div class="animate-twinkle absolute left-10 top-1/2 h-1.5 w-1.5 rounded-full bg-white blur-[1px]" style="animation-delay: 2s"></div>
                <div class="animate-twinkle absolute right-1/3 top-20 h-2 w-2 rounded-full bg-yellow-600/60 blur-[1px]" style="animation-delay: 1.5s"></div>
            </div>

            <header class="ista-navbar">
                <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-5 py-4 sm:px-10">
                    <a href="{{ route('dashboard') }}" class="group flex items-center gap-3">
                        <img src="{{ asset('images/ista/logo.png') }}" alt="ISTA AI" class="h-8 w-8 object-contain transition-transform duration-300 group-hover:rotate-6 group-hover:scale-110">
                        <div class="ista-brand-title text-xl text-ista-primary not-italic">ISTA <span class="font-light italic text-ista-gold">AI</span></div>
                    </a>
                    <div class="flex items-center gap-2 sm:gap-4">
                        <button type="button" @click="darkMode = !darkMode" :aria-pressed="darkMode ? 'true' : 'false'" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-stone-200/70 bg-white/80 text-stone-600 shadow-sm backdrop-blur-md transition hover:text-ista-primary dark:border-gray-700 dark:bg-gray-900/80 dark:text-gray-300 dark:hover:text-amber-300" aria-label="Toggle dark mode">
                            <svg x-show="!darkMode" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v2.5M12 18.5V21M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M3 12h2.5M18.5 12H21M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8M12 16a4 4 0 100-8 4 4 0 000 8z" />
                            </svg>
                            <svg x-show="darkMode" style="display: none;" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" />
                            </svg>
                        </button>
                        <livewire:dashboard-nav-profile />
                    </div>
                </div>
            </header>

            @php
                $profileReturn = match (request()->query('from')) {
                    'chat' => [
                        'label' => 'Kembali ke Chat',
                        'url' => route('chat', ['tab' => 'chat']),
                    ],
                    'memo' => [
                        'label' => 'Kembali ke Memo',
                        'url' => route('chat', ['tab' => 'memo']),
                    ],
                    default => null,
                };
            @endphp

            <main class="relative z-10 mx-auto flex min-h-[calc(100vh-136px)] w-full max-w-[640px] flex-col items-center pt-8 pb-20 px-5 sm:px-10 font-sans">
                @if ($profileReturn)
                    <a href="{{ $profileReturn['url'] }}" class="group mb-4 inline-flex self-start items-center gap-2 rounded-full border border-white/40 bg-white/70 px-4 py-2 text-[13px] font-bold text-stone-700 shadow-sm backdrop-blur-md transition hover:-translate-x-0.5 hover:border-ista-primary/40 hover:text-ista-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary dark:border-gray-700/80 dark:bg-gray-900/70 dark:text-gray-200 dark:hover:border-amber-300/40 dark:hover:text-amber-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform group-hover:-translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                        </svg>
                        <span>{{ $profileReturn['label'] }}</span>
                    </a>
                @endif

                <div class="w-full" x-data="{ activeTab: 'profile', tabs: ['profile', 'password', 'delete'], selectTab(tab) { this.activeTab = tab; this.$nextTick(() => document.getElementById(`${tab}-tab`)?.focus()); }, moveTab(delta) { const current = this.tabs.indexOf(this.activeTab); const next = (current + delta + this.tabs.length) % this.tabs.length; this.selectTab(this.tabs[next]); } }">
                    <div class="group/card ista-glass-card">
                        <div class="absolute inset-0 z-0 -translate-x-[200%] bg-gradient-to-tr from-white/0 via-white/40 to-white/0 group-hover/card:animate-[shimmer_1s_ease-out]"></div>

                        <div class="relative z-20 px-10 pb-6 pt-8 text-center">
                            <h1 class="ista-brand-title mb-1 flex items-center justify-center gap-2 cursor-default text-4xl tracking-tight drop-shadow-sm transition-all duration-300 not-italic">
                                <span class="text-stone-900 not-italic dark:text-gray-100">Pengaturan</span> <span class="text-ista-primary not-italic">Profil</span>
                            </h1>
                            <p class="cursor-default text-[13px] font-medium text-stone-600 opacity-90 dark:text-gray-300">Kelola informasi akun dan keamanan Anda.</p>
                        </div>

                        <!-- Tab Navigation -->
                        <div class="relative z-20 flex border-b border-white/30 px-4 mt-2 dark:border-gray-700/80" role="tablist" aria-label="Pengaturan profil">
                            <button @click="selectTab('profile')" @keydown.arrow-right.prevent="moveTab(1)" @keydown.arrow-left.prevent="moveTab(-1)" role="tab" id="profile-tab" aria-controls="profile-panel" :aria-selected="activeTab === 'profile' ? 'true' : 'false'" :tabindex="activeTab === 'profile' ? 0 : -1"
                                     :class="{ 'border-ista-primary text-ista-primary': activeTab === 'profile', 'border-transparent text-stone-500 hover:text-stone-700 hover:border-stone-300/50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-gray-600': activeTab !== 'profile' }"
                                    class="flex-1 border-b-2 px-4 py-3 text-sm font-bold transition-all duration-300 outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary focus:bg-transparent active:bg-transparent [-webkit-tap-highlight-color:transparent]">
                                Informasi Profil
                            </button>
                            <button @click="selectTab('password')" @keydown.arrow-right.prevent="moveTab(1)" @keydown.arrow-left.prevent="moveTab(-1)" role="tab" id="password-tab" aria-controls="password-panel" :aria-selected="activeTab === 'password' ? 'true' : 'false'" :tabindex="activeTab === 'password' ? 0 : -1"
                                     :class="{ 'border-ista-primary text-ista-primary': activeTab === 'password', 'border-transparent text-stone-500 hover:text-stone-700 hover:border-stone-300/50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-gray-600': activeTab !== 'password' }"
                                    class="flex-1 border-b-2 px-4 py-3 text-sm font-bold transition-all duration-300 outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary focus:bg-transparent active:bg-transparent [-webkit-tap-highlight-color:transparent]">
                                Ubah Kata Sandi
                            </button>
                            <button @click="selectTab('delete')" @keydown.arrow-right.prevent="moveTab(1)" @keydown.arrow-left.prevent="moveTab(-1)" role="tab" id="delete-tab" aria-controls="delete-panel" :aria-selected="activeTab === 'delete' ? 'true' : 'false'" :tabindex="activeTab === 'delete' ? 0 : -1"
                                     :class="{ 'border-ista-primary text-ista-primary': activeTab === 'delete', 'border-transparent text-stone-500 hover:text-stone-700 hover:border-stone-300/50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-gray-600': activeTab !== 'delete' }"
                                    class="flex-1 border-b-2 px-4 py-3 text-sm font-bold transition-all duration-300 outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ista-primary focus:bg-transparent active:bg-transparent [-webkit-tap-highlight-color:transparent]">
                                Hapus Akun
                            </button>
                        </div>

                        <!-- Tab Contents -->
                        <div class="relative z-20 p-6 sm:p-10 min-h-[420px]">
                            <!-- Profile Information Form -->
                            <div x-show="activeTab === 'profile'" role="tabpanel" id="profile-panel" aria-labelledby="profile-tab"
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-y-4"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 style="display: none;">
                                <livewire:profile.update-profile-information-form />
                            </div>

                            <!-- Update Password Form -->
                            <div x-show="activeTab === 'password'" role="tabpanel" id="password-panel" aria-labelledby="password-tab"
                                 x-transition:enter="transition ease-out duration-300"
                                 x-transition:enter-start="opacity-0 translate-y-4"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 style="display: none;">
                                <livewire:profile.update-password-form />
                            </div>

                            <!-- Delete User Form -->
                            <div x-show="activeTab === 'delete'" role="tabpanel" id="delete-panel" aria-labelledby="delete-tab"
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
