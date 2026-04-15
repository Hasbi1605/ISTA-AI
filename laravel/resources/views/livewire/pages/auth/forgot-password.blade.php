<?php

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.auth-canvas')] class extends Component
{
    public string $email = '';

    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ], [], [
            'email' => 'email',
        ]);

        $user = User::where('email', $this->email)->first();

        if ($user && is_null($user->email_verified_at)) {
            $this->addError('email', 'Email belum terverifikasi. Silakan daftar ulang lalu verifikasi kode OTP.');

            return;
        }

        $status = Password::sendResetLink(
            $this->only('email')
        );

        if ($status != Password::RESET_LINK_SENT) {
            $this->addError('email', __($status));

            return;
        }

        $this->reset('email');

        session()->flash('status', __($status));
    }
}; ?>

<div class="relative flex min-h-screen w-full items-center justify-center overflow-hidden bg-[#fafaf9]">
    <div class="absolute inset-0 z-0">
        <div class="h-full w-full animate-breathe bg-cover bg-center opacity-100" style="background-image: url('/images/ista/login-bg.png');"></div>
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(212,175,55,0.15)_0%,rgba(250,250,249,0.4)_80%)]"></div>
        <div class="absolute inset-0 bg-gradient-to-t from-ista-gold/20 via-white/5 to-transparent"></div>
    </div>

    <div class="pointer-events-none absolute inset-0 z-0 overflow-hidden">
        <div class="animate-float-slow absolute left-1/4 top-1/4 h-64 w-64 cursor-pointer rounded-full bg-yellow-400/20 mix-blend-overlay blur-3xl"></div>
        <div class="animate-float-reverse absolute bottom-1/4 right-1/4 h-80 w-80 cursor-pointer rounded-full bg-rose-500/10 mix-blend-multiply blur-3xl"></div>

        <div class="animate-twinkle absolute left-20 top-10 h-2 w-2 cursor-pointer rounded-full bg-yellow-500 blur-[1px]" style="animation-delay: 0s"></div>
        <div class="animate-twinkle absolute bottom-20 right-10 h-3 w-3 cursor-pointer rounded-full bg-rose-400 blur-[2px]" style="animation-delay: 1s"></div>
        <div class="animate-twinkle absolute left-10 top-1/2 h-1.5 w-1.5 cursor-pointer rounded-full bg-white blur-[1px]" style="animation-delay: 2s"></div>
        <div class="animate-twinkle absolute right-1/3 top-20 h-2 w-2 cursor-pointer rounded-full bg-yellow-600/60 blur-[1px]" style="animation-delay: 1.5s"></div>
    </div>

    <div class="relative z-10 w-full max-w-[640px] p-6 font-sans transition-all duration-500">
        <div class="group/card ista-glass-card cursor-pointer">
            <div class="absolute inset-0 z-0 -translate-x-[200%] bg-gradient-to-tr from-white/0 via-white/40 to-white/0 group-hover/card:animate-[shimmer_1s_ease-out]"></div>

            <div class="animate-enter-1 relative z-20 px-10 pb-6 pt-8 text-center">
                <div class="group/logo mb-4 inline-flex h-16 w-16 cursor-pointer items-center justify-center rounded-2xl border border-white/40 bg-white/80 shadow-sm transition-transform duration-500 hover:scale-110 hover:rotate-6">
                    <img src="{{ asset('images/ista/logo.png') }}" class="h-9 w-9 object-contain group-hover/logo:brightness-110" alt="Logo">
                </div>

                <h1 class="ista-brand-title mb-1 flex items-center justify-center gap-2 cursor-default text-4xl tracking-tight drop-shadow-sm transition-all duration-300 not-italic">
                    <span class="text-stone-900 not-italic">Lupa Kata Sandi</span>
                </h1>
                <p class="cursor-default text-[13px] font-medium text-stone-600 opacity-90">Asisten Istana Pintar</p>
            </div>

            <div class="relative z-20 px-12 pb-10">
                @if (session('status'))
                    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                        {{ session('status') }}
                    </div>
                @endif

                <form wire:submit="sendPasswordResetLink" class="space-y-6">
                    <div class="mb-4 text-[13px] font-medium text-stone-600">
                        Masukkan alamat email Anda untuk menerima tautan reset kata sandi.
                    </div>

                    <div class="animate-enter-2 group space-y-2">
                        <label for="email" class="ml-1 cursor-text text-[13px] font-bold text-stone-600 transition-all duration-300 group-focus-within:translate-x-1 group-focus-within:text-rose-900">Alamat Email</label>
                        <div class="relative transform transition-transform duration-300 group-focus-within:scale-[1.01]">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-5">
                                <svg class="h-5 w-5 text-stone-500 transition-colors duration-300 group-focus-within:text-rose-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <input wire:model="email" id="email" class="ista-input" type="email" name="email" placeholder="email@contoh.com" required autofocus />
                        </div>
                        @if ($errors->has('email'))
                            <p class="ml-1 mt-1 animate-pulse text-[12px] font-bold text-rose-600">{{ $errors->first('email') }}</p>
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
                            <a href="{{ route('login') }}" class="text-[13px] font-bold text-ista-primary transition-colors hover:text-ista-gold">Kembali ke Login</a>
                        </div>
                    </div>
                </form>

                <div class="animate-enter-4 mt-8 border-t border-rose-900/10 pt-4 text-center">
                    <p class="inline-block cursor-pointer text-[12px] font-semibold text-rose-950/60 transition-colors hover:scale-105 hover:text-rose-900">
                        Copyright © 2026 Istana Kepresidenan Yogyakarta, All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
