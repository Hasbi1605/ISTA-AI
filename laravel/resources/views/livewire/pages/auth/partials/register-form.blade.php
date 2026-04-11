<form wire:submit="register" class="space-y-4">
    <div class="animate-enter-1 group space-y-2">
        <label for="name" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Nama Lengkap</label>
        <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
            </div>
            <input wire:model="name" id="name" class="ista-input" type="text" name="name" placeholder="Nama Anda" required autofocus autocomplete="name" />
        </div>
        @if ($errors->has('name'))
            <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('name') }}</p>
        @endif
    </div>

    <div class="animate-enter-2 group space-y-2">
        <label for="register_email" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Alamat Email</label>
        <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
            </div>
            <input wire:model="register_email" id="register_email" class="ista-input" type="email" name="register_email" placeholder="email@contoh.com" required autocomplete="username" />
        </div>
        @if ($errors->has('register_email'))
            <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('register_email') }}</p>
        @endif
    </div>

    <div class="animate-enter-3 group space-y-2">
        <label for="register_password" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Kata sandi</label>
        <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 00-2 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <input wire:model="register_password" id="register_password" class="ista-input" type="password" name="register_password" placeholder="••••••••" required autocomplete="new-password" />
        </div>
        @if ($errors->has('register_password'))
            <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('register_password') }}</p>
        @endif
    </div>

    <div class="animate-enter-4 group space-y-2">
        <label for="password_confirmation" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Konfirmasi Kata sandi</label>
        <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 00-2 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <input wire:model="password_confirmation" id="password_confirmation" class="ista-input" type="password" name="password_confirmation" placeholder="••••••••" required autocomplete="new-password" />
        </div>
    </div>

    <div class="animate-enter-4 pt-2">
        <button type="submit" class="ista-login-button group">
            <span class="relative z-10 flex items-center justify-center gap-2 text-[15px] font-semibold text-white transition-all duration-500 ease-out">
                Daftar Sekarang
                <svg class="-ml-4 h-4 w-4 opacity-0 transition-all duration-500 group-hover:ml-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </span>
            <div class="absolute inset-0 z-0 -translate-x-[150%] skew-x-12 bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-1000 ease-in-out group-hover:translate-x-[150%]"></div>
        </button>

        <div class="mt-4 text-center">
            <button type="button" wire:click="toggleRegister" class="text-[13px] font-bold text-ista-primary transition-colors hover:text-ista-gold">Sudah punya akun? Masuk Portal</button>
        </div>
    </div>
</form>