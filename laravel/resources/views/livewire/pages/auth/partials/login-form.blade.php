<form wire:submit="login" class="space-y-6">
    <div class="animate-enter-2 group space-y-2">
        <label for="email" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Identitas akses</label>
        <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
            </div>
            <input wire:model="form.email" id="email" class="ista-input" type="email" name="email" placeholder="Email atau username" required autofocus autocomplete="username" />
        </div>
        @if ($errors->has('form.email'))
            <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('form.email') }}</p>
        @endif
    </div>

    <div class="animate-enter-3 group space-y-2">
        <label for="password" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Kata sandi</label>
        <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 00-2 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <input wire:model="form.password" id="password" class="ista-input" type="password" name="password" placeholder="••••••••" required autocomplete="current-password" />
        </div>
        @if ($errors->has('form.password'))
            <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('form.password') }}</p>
        @endif
    </div>

    <div class="animate-enter-3 flex items-center pt-2">
        <label for="remember" class="group flex cursor-pointer select-none items-center">
            <input wire:model="form.remember" id="remember" type="checkbox" name="remember" class="h-4 w-4 cursor-pointer rounded border-stone-400 bg-white/50 text-rose-700 transition-all focus:ring-0 focus:ring-offset-0 checked:bg-rose-700">
            <span class="ml-2.5 cursor-pointer text-[13px] font-bold text-stone-600 transition-colors group-hover:text-rose-900">Ingat sesi saya</span>
        </label>
    </div>

    <div class="animate-enter-4">
        <button type="submit" class="ista-login-button group">
            <span class="relative z-10 flex items-center justify-center gap-2 text-[15px] font-semibold text-white transition-all duration-500 ease-out">
                Masuk Portal
                <svg class="-ml-4 h-4 w-4 opacity-0 transition-all duration-500 group-hover:ml-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </span>
            <div class="absolute inset-0 z-0 -translate-x-[150%] skew-x-12 bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-1000 ease-in-out group-hover:translate-x-[150%]"></div>
        </button>

        <div class="mt-4 flex flex-col items-center gap-2 sm:flex-row sm:justify-center sm:gap-4">
            @if (Route::has('password.request'))
                <button type="button" wire:click="setView('forgot-password')" class="text-[13px] font-bold text-ista-primary transition-colors hover:text-ista-gold">Lupa kata sandi?</button>
            @endif
            <span class="hidden text-stone-300 sm:inline">•</span>
            <button type="button" wire:click="toggleRegister" class="text-[13px] font-bold text-ista-primary transition-colors hover:text-ista-gold">Belum punya akun? Daftar</button>
        </div>
    </div>
</form>