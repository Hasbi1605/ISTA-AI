<?php

use App\Livewire\Forms\LoginForm;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.auth-canvas')] class extends Component
{
    public LoginForm $form;

    public string $view = 'login';

    // Register fields
    public string $name = '';
    public string $register_email = '';
    public string $register_password = '';
    public string $password_confirmation = '';

    // Forgot Password fields
    public string $forgot_email = '';
    public ?string $forgot_status = null;

    public function setView(string $view): void
    {
        $this->view = $view;
        $this->resetErrorBag();
        $this->forgot_status = null;
    }

    public function toggleRegister(): void
    {
        $this->setView($this->view === 'register' ? 'login' : 'register');
    }

    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'forgot_email' => ['required', 'email'],
        ], [], [
            'forgot_email' => 'email',
        ]);

        $status = Password::broker()->sendResetLink(
            ['email' => $this->forgot_email]
        );

        if ($status == Password::RESET_LINK_SENT) {
            $this->forgot_status = __($status);
            $this->forgot_email = '';
        } else {
            $this->addError('forgot_email', __($status));
        }
    }

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate([
            'form.email' => 'required|string|email',
            'form.password' => 'required|string',
        ]);

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'register_email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class.',email'],
            'register_password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'register_email.required' => 'Alamat email wajib diisi.',
            'register_email.unique' => 'Email ini sudah terdaftar.',
            'register_email.email' => 'Format email tidak valid.',
            'register_password.required' => 'Kata sandi wajib diisi.',
            'register_password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ], [
            'register_email' => 'email',
            'register_password' => 'password',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['register_email'],
            'password' => Hash::make($validated['register_password']),
        ]);

        event(new Registered($user));

        Auth::login($user);

        Session::regenerate();

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="relative flex min-h-screen w-full items-center justify-center overflow-hidden bg-[#fafaf9]">
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

    <div class="relative z-10 w-full max-w-[640px] p-6 font-sans">
        <div class="group/card ista-glass-card cursor-pointer">
            <div class="absolute inset-0 z-0 -translate-x-[200%] bg-gradient-to-tr from-white/0 via-white/40 to-white/0 group-hover/card:animate-[shimmer_1s_ease-out]"></div>

            <div class="animate-enter-1 relative z-20 px-10 pb-6 pt-8 text-center">
                <div class="group/logo mb-4 inline-flex h-16 w-16 cursor-pointer items-center justify-center rounded-2xl border border-white/40 bg-white/80 shadow-sm transition-transform duration-500 hover:scale-110 hover:rotate-6">
                    <img src="{{ asset('images/ista/logo.png') }}" class="h-9 w-9 object-contain group-hover/logo:brightness-110" alt="Logo">
                </div>

                <h1 class="ista-brand-title mb-1 flex items-center justify-center gap-2 cursor-default text-4xl tracking-tight drop-shadow-sm transition-all duration-300 not-italic">
                    <span class="text-stone-900 not-italic">Login</span> <span class="text-[#8b0836] not-italic">ISTA <span class="font-light italic text-[#d4af37]">AI</span></span>
                </h1>
                <p class="cursor-default text-[13px] font-medium text-stone-600 opacity-90">Asisten Istana Pintar</p>
            </div>

            <div class="relative z-20 px-12 pb-10">
                <x-auth-session-status class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700" :status="session('status')" />

                @if($view === 'login')
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
                                <button type="button" wire:click="setView('forgot-password')" class="text-[13px] font-bold text-[#8b0836] transition-colors hover:text-[#d4af37]">Lupa kata sandi?</button>
                            @endif
                            <span class="hidden text-stone-300 sm:inline">•</span>
                            <button type="button" wire:click="toggleRegister" class="text-[13px] font-bold text-[#8b0836] transition-colors hover:text-[#d4af37]">Belum punya akun? Daftar</button>
                        </div>
                    </div>
                </form>
                @elseif($view === 'register')
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
                            <button type="button" wire:click="toggleRegister" class="text-[13px] font-bold text-[#8b0836] transition-colors hover:text-[#d4af37]">Sudah punya akun? Masuk Portal</button>
                        </div>
                    </div>
                                </form>
                @elseif($view === 'forgot-password')
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
                        <button type="submit" class="ista-login-button group">
                            <span class="relative z-10 flex items-center justify-center gap-2 text-[15px] font-semibold text-white transition-all duration-500 ease-out">
                                Kirim Tautan Reset Kata Sandi
                                <svg class="-ml-4 h-4 w-4 opacity-0 transition-all duration-500 group-hover:ml-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </span>
                            <div class="absolute inset-0 z-0 -translate-x-[150%] skew-x-12 bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-1000 ease-in-out group-hover:translate-x-[150%]"></div>
                        </button>

                        <div class="mt-4 text-center">
                            <button type="button" wire:click="setView('login')" class="text-[13px] font-bold text-[#8b0836] transition-colors hover:text-[#d4af37]">Kembali ke Login</button>
                        </div>
                    </div>
                </form>
                @endif

                <div class="animate-enter-4 mt-6 text-center">
                    <a href="{{ url('/') }}" class="group inline-flex items-center gap-2 text-xs font-bold text-rose-900 transition-colors hover:text-amber-600">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 cursor-pointer transition-transform duration-300 group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        <span>Kembali ke Beranda</span>
                    </a>
                </div>

                <div class="animate-enter-4 mt-8 border-t border-rose-900/10 pt-4 text-center">
                    <p class="inline-block cursor-pointer text-[12px] font-semibold text-rose-950/60 transition-colors hover:scale-105 hover:text-rose-900">
                        Copyright © 2026 Istana Kepresidenan Yogyakarta, All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
