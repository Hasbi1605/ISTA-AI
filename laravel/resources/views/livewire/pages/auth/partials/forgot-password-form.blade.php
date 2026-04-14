<form wire:submit="sendPasswordResetLink" class="space-y-6">
    <div class="mb-4 text-[13px] font-medium text-stone-600">
        Masukkan alamat email Anda untuk menerima tautan reset kata sandi.
    </div>

    @if ($forgot_status)
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
            {{ $forgot_status }}
        </div>
    @endif

    <div class="animate-enter-2 group space-y-2">
        <label for="forgot_email" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Alamat Email</label>
        <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
            </div>
            <input wire:model="forgot_email" id="forgot_email" class="ista-input" type="email" name="forgot_email" placeholder="email@contoh.com" required autofocus />
        </div>
        @if ($errors->has('forgot_email'))
            <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('forgot_email') }}</p>
        @endif
    </div>

    <div class="animate-enter-4">
        <button type="submit" class="ista-login-button group" wire:loading.attr="disabled" wire:target="sendPasswordResetLink">
            <span class="relative z-10 flex items-center justify-center gap-2 text-[15px] font-semibold text-white transition-all duration-500 ease-out">
                <span wire:loading.remove wire:target="sendPasswordResetLink">Kirim Tautan Reset Kata Sandi</span>
                <span wire:loading.flex wire:target="sendPasswordResetLink" class="items-center justify-center gap-2">
                    <svg class="h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Memproses...
                </span>
                <svg wire:loading.remove wire:target="sendPasswordResetLink" class="-ml-4 h-4 w-4 opacity-0 transition-all duration-500 group-hover:ml-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </span>
            <div class="absolute inset-0 z-0 -translate-x-[150%] skew-x-12 bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-1000 ease-in-out group-hover:translate-x-[150%]"></div>
        </button>

        <div class="mt-4 text-center">
            <button type="button" wire:click="setView('login')" class="text-[13px] font-bold text-ista-primary transition-colors hover:text-ista-gold">Kembali ke Login</button>
        </div>
    </div>
</form>
