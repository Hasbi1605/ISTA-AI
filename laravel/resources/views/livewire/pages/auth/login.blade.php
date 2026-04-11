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

    <div class="relative z-10 w-full max-w-[640px] p-6 font-sans">
        <div class="group/card ista-glass-card cursor-pointer">
            <div class="absolute inset-0 z-0 -translate-x-[200%] bg-gradient-to-tr from-white/0 via-white/40 to-white/0 group-hover/card:animate-[shimmer_1s_ease-out]"></div>

            <div class="animate-enter-1 relative z-20 px-10 pb-6 pt-8 text-center">
                <div class="group/logo mb-4 inline-flex h-16 w-16 cursor-pointer items-center justify-center rounded-2xl border border-white/40 bg-white/80 shadow-sm transition-transform duration-500 hover:scale-110 hover:rotate-6">
                    <img src="{{ asset('images/ista/logo.png') }}" class="h-9 w-9 object-contain group-hover/logo:brightness-110" alt="Logo">
                </div>

                <h1 class="ista-brand-title mb-1 flex items-center justify-center gap-2 cursor-default text-4xl tracking-tight drop-shadow-sm transition-all duration-300 not-italic">
                    <span class="text-stone-900 not-italic">Login</span> <span class="text-ista-primary not-italic">ISTA <span class="font-light italic text-ista-gold">AI</span></span>
                </h1>
                <p class="cursor-default text-[13px] font-medium text-stone-600 opacity-90">Asisten Istana Pintar</p>
            </div>

            <div class="relative z-20 px-12 pb-10">
                <x-auth-session-status class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700" :status="session('status')" />

                @if($view === 'login')
                    @include('livewire.pages.auth.partials.login-form')
                @elseif($view === 'register')
                    @include('livewire.pages.auth.partials.register-form')
                @elseif($view === 'forgot-password')
                    @include('livewire.pages.auth.partials.forgot-password-form')
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
