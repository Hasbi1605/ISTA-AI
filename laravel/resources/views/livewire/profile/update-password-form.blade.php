<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<section>
    <header>
        <h2 class="text-xl font-bold text-[#8b0836]">
            Perbarui Kata Sandi
        </h2>

        <p class="mt-1 text-sm text-stone-600 font-medium">
            Pastikan akun Anda menggunakan kata sandi yang panjang dan acak untuk tetap aman.
        </p>
    </header>

    <form wire:submit="updatePassword" class="mt-6 space-y-6">
        <div class="group space-y-2">
            <label for="update_password_current_password" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Kata Sandi Saat Ini</label>
            <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                    <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 00-2 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>
                <input wire:model="current_password" id="update_password_current_password" name="current_password" type="password" class="ista-input" autocomplete="current-password" />
            </div>
            @if ($errors->has('current_password'))
                <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('current_password') }}</p>
            @endif
        </div>

        <div class="group space-y-2">
            <label for="update_password_password" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Kata Sandi Baru</label>
            <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                    <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 00-2 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>
                <input wire:model="password" id="update_password_password" name="password" type="password" class="ista-input" autocomplete="new-password" />
            </div>
            @if ($errors->has('password'))
                <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('password') }}</p>
            @endif
        </div>

        <div class="group space-y-2">
            <label for="update_password_password_confirmation" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Konfirmasi Kata Sandi Baru</label>
            <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                    <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 00-2 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>
                <input wire:model="password_confirmation" id="update_password_password_confirmation" name="password_confirmation" type="password" class="ista-input" autocomplete="new-password" />
            </div>
            @if ($errors->has('password_confirmation'))
                <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('password_confirmation') }}</p>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="ista-login-button group w-auto px-6 py-2">
                <span class="relative z-10 flex items-center justify-center gap-2 text-[15px] font-semibold text-white transition-all duration-500 ease-out">
                    Simpan
                </span>
                <div class="absolute inset-0 z-0 -translate-x-[150%] skew-x-12 bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-1000 ease-in-out group-hover:translate-x-[150%]"></div>
            </button>

            <x-action-message class="me-3 text-[#d4af37] text-sm font-bold" on="password-updated">
                Tersimpan.
            </x-action-message>
        </div>
    </form>
</section>
