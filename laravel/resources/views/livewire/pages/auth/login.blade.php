<?php

use App\Livewire\Forms\LoginForm;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
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

    public string $register_password_confirmation = '';

    // Forgot Password fields
    public string $forgot_email = '';

    public ?string $forgot_status = null;

    // OTP Verification Modal
    public bool $showVerificationModal = false;

    public string $verification_code_input = '';

    public ?string $pendingRegistrationToken = null;

    public ?string $otp_status = null;

    public function mount(): void
    {
        if (request()->query('view') === 'register') {
            $this->view = 'register';
        }
    }

    protected function pendingRegistrationKey(string $token): string
    {
        return 'pending_registration:'.$token;
    }

    protected function pendingRegistrationEmailKey(string $email): string
    {
        return 'pending_registration_email:'.Str::lower($email);
    }

    protected function otpRateLimitKey(?string $token = null): ?string
    {
        $token ??= $this->pendingRegistrationToken;

        if (! $token) {
            return null;
        }

        return 'otp_registration:'.$token.'|'.request()->ip();
    }

    protected function otpResendRateLimitKey(?string $token = null): ?string
    {
        $token ??= $this->pendingRegistrationToken;

        if (! $token) {
            return null;
        }

        return 'otp_registration_resend:'.$token.'|'.request()->ip();
    }

    protected function pendingRegistrationTtlMinutes(): int
    {
        return max(1, (int) config('auth.otp_registration.ttl_minutes', 60));
    }

    protected function otpMaxAttempts(): int
    {
        return max(1, (int) config('auth.otp_registration.max_attempts', 3));
    }

    protected function otpDecaySeconds(): int
    {
        return max(1, (int) config('auth.otp_registration.decay_seconds', 600));
    }

    protected function otpResendCooldownSeconds(): int
    {
        return max(1, (int) config('auth.otp_registration.resend_cooldown_seconds', 60));
    }

    protected function clearPendingRegistration(?string $token = null, ?string $email = null): void
    {
        if ($token) {
            Cache::forget($this->pendingRegistrationKey($token));

            $otpRateLimitKey = $this->otpRateLimitKey($token);
            if ($otpRateLimitKey) {
                RateLimiter::clear($otpRateLimitKey);
            }

            $otpResendRateLimitKey = $this->otpResendRateLimitKey($token);
            if ($otpResendRateLimitKey) {
                RateLimiter::clear($otpResendRateLimitKey);
            }
        }

        if ($email) {
            Cache::forget($this->pendingRegistrationEmailKey($email));
        }
    }

    public function setView(string $view): void
    {
        $this->view = $view;
        $this->resetErrorBag();
        $this->forgot_status = null;
        $this->otp_status = null;
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

        $user = User::where('email', $this->forgot_email)->first();

        if ($user && is_null($user->email_verified_at)) {
            $this->addError('forgot_email', 'Email belum terverifikasi. Silakan daftar ulang lalu verifikasi kode OTP.');

            return;
        }

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
        $existingUser = User::where('email', $this->register_email)->first();
        if ($existingUser && is_null($existingUser->email_verified_at)) {
            $existingUser->delete();
        }

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

        $registrationEmail = Str::lower($validated['register_email']);
        $existingPendingToken = Cache::get($this->pendingRegistrationEmailKey($registrationEmail));
        if ($existingPendingToken) {
            $this->clearPendingRegistration($existingPendingToken, $registrationEmail);
        }

        $plainCode = sprintf('%06d', random_int(0, 999999));
        $pendingToken = (string) Str::uuid();

        $ttlMinutes = $this->pendingRegistrationTtlMinutes();

        Cache::put($this->pendingRegistrationKey($pendingToken), [
            'name' => $validated['name'],
            'email' => $registrationEmail,
            'password' => Hash::make($validated['register_password']),
            'code_hash' => hash('sha256', $plainCode),
            'expires_at' => now()->addMinutes($ttlMinutes)->getTimestamp(),
        ], now()->addMinutes($ttlMinutes));

        Cache::put(
            $this->pendingRegistrationEmailKey($registrationEmail),
            $pendingToken,
            now()->addMinutes($ttlMinutes)
        );

        Mail::to($registrationEmail)->send(new VerificationCodeMail($plainCode));

        $this->pendingRegistrationToken = $pendingToken;
        $this->showVerificationModal = true;
        $this->verification_code_input = '';
        $this->otp_status = null;
    }

    public function resendOtp(): void
    {
        if (! $this->pendingRegistrationToken) {
            $this->addError('verification_code_input', 'Sesi pendaftaran tidak ditemukan. Silakan daftar ulang.');

            return;
        }

        $pending = Cache::get($this->pendingRegistrationKey($this->pendingRegistrationToken));

        if (! is_array($pending)) {
            $this->addError('verification_code_input', 'Sesi pendaftaran sudah berakhir. Silakan daftar ulang.');

            return;
        }

        $otpResendRateLimitKey = $this->otpResendRateLimitKey();

        if ($otpResendRateLimitKey && RateLimiter::tooManyAttempts($otpResendRateLimitKey, 1)) {
            $seconds = RateLimiter::availableIn($otpResendRateLimitKey);
            $this->addError('verification_code_input', 'Kode OTP sudah dikirim ulang. Coba lagi dalam '.$seconds.' detik.');

            return;
        }

        $plainCode = sprintf('%06d', random_int(0, 999999));
        $ttlMinutes = $this->pendingRegistrationTtlMinutes();
        $email = (string) ($pending['email'] ?? '');

        Cache::put($this->pendingRegistrationKey($this->pendingRegistrationToken), [
            'name' => (string) ($pending['name'] ?? ''),
            'email' => $email,
            'password' => (string) ($pending['password'] ?? ''),
            'code_hash' => hash('sha256', $plainCode),
            'expires_at' => now()->addMinutes($ttlMinutes)->getTimestamp(),
        ], now()->addMinutes($ttlMinutes));

        Cache::put(
            $this->pendingRegistrationEmailKey($email),
            $this->pendingRegistrationToken,
            now()->addMinutes($ttlMinutes)
        );

        Mail::to($email)->send(new VerificationCodeMail($plainCode));

        if ($otpResendRateLimitKey) {
            RateLimiter::hit($otpResendRateLimitKey, $this->otpResendCooldownSeconds());
        }

        $this->verification_code_input = '';
        $this->otp_status = 'Kode OTP baru telah dikirim ke email Anda.';
    }

    public function cancelVerification(): void
    {
        if (! $this->pendingRegistrationToken) {
            $this->showVerificationModal = false;

            return;
        }

        $pending = Cache::get($this->pendingRegistrationKey($this->pendingRegistrationToken));
        $pendingEmail = is_array($pending) ? ($pending['email'] ?? null) : null;

        $this->clearPendingRegistration($this->pendingRegistrationToken, $pendingEmail);

        $this->pendingRegistrationToken = null;
        $this->verification_code_input = '';
        $this->showVerificationModal = false;
        $this->otp_status = null;
    }

    public function verifyOtp(): void
    {
        $this->validate([
            'verification_code_input' => ['required', 'digits:6'],
        ], [
            'verification_code_input.required' => 'Kode verifikasi wajib diisi.',
            'verification_code_input.digits' => 'Kode verifikasi harus 6 digit.',
        ]);

        if (! $this->pendingRegistrationToken) {
            $this->addError('verification_code_input', 'Sesi pendaftaran tidak ditemukan. Silakan daftar ulang.');

            return;
        }

        $otpRateLimitKey = $this->otpRateLimitKey();
        if ($otpRateLimitKey && RateLimiter::tooManyAttempts($otpRateLimitKey, $this->otpMaxAttempts())) {
            $seconds = RateLimiter::availableIn($otpRateLimitKey);
            $this->addError('verification_code_input', 'Terlalu banyak percobaan OTP. Coba lagi dalam '.ceil($seconds / 60).' menit.');

            return;
        }

        $pending = Cache::get($this->pendingRegistrationKey($this->pendingRegistrationToken));

        if (! is_array($pending)) {
            $this->addError('verification_code_input', 'Sesi pendaftaran sudah berakhir. Silakan daftar ulang.');

            return;
        }

        $expiresAt = (int) ($pending['expires_at'] ?? 0);
        if ($expiresAt < now()->getTimestamp()) {
            $this->clearPendingRegistration($this->pendingRegistrationToken, $pending['email'] ?? null);
            $this->addError('verification_code_input', 'Kode verifikasi sudah kedaluwarsa. Silakan daftar ulang.');

            return;
        }

        $providedCodeHash = hash('sha256', $this->verification_code_input);

        if (! hash_equals((string) ($pending['code_hash'] ?? ''), $providedCodeHash)) {
            if ($otpRateLimitKey) {
                RateLimiter::hit($otpRateLimitKey, $this->otpDecaySeconds());
            }

            $this->addError('verification_code_input', 'Kode verifikasi tidak valid.');

            return;
        }

        $email = (string) ($pending['email'] ?? '');

        try {
            $user = DB::transaction(function () use ($email, $pending) {
                $legacyUnverifiedUser = User::where('email', $email)
                    ->whereNull('email_verified_at')
                    ->first();

                if ($legacyUnverifiedUser) {
                    $legacyUnverifiedUser->delete();
                }

                $user = User::create([
                    'name' => (string) ($pending['name'] ?? ''),
                    'email' => $email,
                    'password' => (string) ($pending['password'] ?? ''),
                    'verification_code' => null,
                    'verification_code_expires_at' => null,
                ]);

                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();

                return $user;
            });
        } catch (Throwable $e) {
            $this->addError('verification_code_input', 'Terjadi kendala saat menyelesaikan pendaftaran. Silakan coba lagi.');

            return;
        }

        if ($otpRateLimitKey) {
            RateLimiter::clear($otpRateLimitKey);
        }

        Auth::login($user);

        Session::regenerate();

        $this->clearPendingRegistration($this->pendingRegistrationToken, $email);
        $this->pendingRegistrationToken = null;
        $this->verification_code_input = '';
        $this->showVerificationModal = false;
        $this->otp_status = null;

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
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

    <div @class([
        'relative w-full max-w-[640px] p-6 font-sans transition-all duration-500',
        'opacity-30 blur-sm pointer-events-none' => $showVerificationModal,
    ]) style="{{ $showVerificationModal ? 'z-index: 0; filter: brightness(0.28) saturate(0.45);' : 'z-index: 10;' }}">
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

                    <div class="animate-enter-4 mt-6 text-center">
                        <a href="{{ url('/') }}" class="group inline-flex items-center gap-2 text-xs font-bold text-rose-900 transition-colors hover:text-amber-600">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 cursor-pointer transition-transform duration-300 group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            <span>Kembali ke Beranda</span>
                        </a>
                    </div>
                @elseif($view === 'register')
                    @include('livewire.pages.auth.partials.register-form')
                @elseif($view === 'forgot-password')
                    @include('livewire.pages.auth.partials.forgot-password-form')
                @endif

                <div class="animate-enter-4 mt-8 border-t border-rose-900/10 pt-4 text-center">
                    <p class="inline-block cursor-pointer text-[12px] font-semibold text-rose-950/60 transition-colors hover:scale-105 hover:text-rose-900">
                        Copyright © 2026 Istana Kepresidenan Yogyakarta, All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </div>

    @if($showVerificationModal)
        <div class="fixed inset-0" style="z-index: 9998; background-color: rgba(0, 0, 0, 0.88); backdrop-filter: blur(8px);"></div>

        <div class="fixed inset-0 z-[9999] flex items-center justify-center p-4">
            <div class="ista-glass-card relative w-full max-w-md p-8 animate-enter-1 border-white/95 bg-white/80 shadow-[0_0_0_1px_rgba(255,255,255,0.65),0_0_110px_rgba(255,255,255,0.4),0_30px_70px_-15px_rgba(0,0,0,0.58)] hover:translate-y-0 hover:shadow-[0_0_0_1px_rgba(255,255,255,0.65),0_0_110px_rgba(255,255,255,0.4),0_30px_70px_-15px_rgba(0,0,0,0.58)]" style="background: linear-gradient(150deg, rgba(255, 255, 255, 0.94), rgba(255, 255, 255, 0.78)); backdrop-filter: blur(22px) saturate(135%);">
                <button wire:click="cancelVerification" class="absolute right-4 top-4 text-stone-500 transition-colors hover:text-stone-700" type="button" aria-label="Tutup popup verifikasi">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <h2 class="mb-2 text-center text-2xl font-bold text-stone-900">Verifikasi Email</h2>
                <p class="mb-6 text-center text-sm text-stone-600">Kami mengirimkan kode 6 digit ke email Anda. Masukkan kode untuk menyelesaikan pendaftaran.</p>

                @if($otp_status)
                    <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-center text-xs font-medium text-emerald-700">{{ $otp_status }}</p>
                @endif

                <form wire:submit="verifyOtp">
                    <div
                        x-data="{
                            otp: ['', '', '', '', '', ''],
                            focusNext(index) {
                                if (this.otp[index] && index < 5) {
                                    document.getElementById('otp_' + (index + 1)).focus();
                                }
                                $wire.set('verification_code_input', this.otp.join(''));
                            },
                            handleBackspace(event, index) {
                                if ((event.key === 'Backspace' || event.key === 'Delete') && !this.otp[index] && index > 0) {
                                    const prevInput = document.getElementById('otp_' + (index - 1));
                                    if (prevInput) {
                                        prevInput.focus();
                                        setTimeout(() => prevInput.select(), 10);
                                    }
                                }
                            },
                            handlePaste(event) {
                                const pastedData = event.clipboardData.getData('text').slice(0, 6);
                                if (/^\d+$/.test(pastedData)) {
                                    for (let i = 0; i < pastedData.length; i++) {
                                        this.otp[i] = pastedData[i];
                                    }
                                    $wire.set('verification_code_input', this.otp.join(''));
                                    const focusIndex = pastedData.length > 5 ? 5 : pastedData.length;
                                    document.getElementById('otp_' + focusIndex).focus();
                                }
                            }
                        }"
                    >
                        <label class="mb-2 block text-center text-sm font-medium text-stone-700">Kode Verifikasi</label>
                        <div class="mb-2 flex justify-center gap-2" @paste.prevent="handlePaste">
                            <template x-for="(digit, index) in otp" :key="index">
                                <input
                                    type="text"
                                    maxlength="1"
                                    x-model="otp[index]"
                                    :id="'otp_' + index"
                                    @input="focusNext(index)"
                                    @keydown="handleBackspace($event, index)"
                                    class="h-14 w-12 rounded-xl border border-stone-300 bg-white text-center text-2xl font-bold text-stone-800 shadow-inner transition-all focus:border-ista-primary focus:outline-none focus:ring-4 focus:ring-ista-primary/10"
                                    required
                                    autofocus
                                >
                            </template>
                        </div>
                        @error('verification_code_input')
                            <span class="mt-1 block text-center text-xs text-rose-600">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="mt-6 pt-2">
                        <button type="submit" class="ista-login-button group w-full" wire:loading.attr="disabled" wire:target="verifyOtp">
                            <span class="relative z-10 flex items-center justify-center gap-2 text-[15px] font-semibold text-white transition-all duration-500 ease-out">
                                <span wire:loading.remove wire:target="verifyOtp">Verifikasi & Login</span>
                                <span wire:loading.flex wire:target="verifyOtp" class="items-center justify-center gap-2">
                                    <svg class="h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Memproses...
                                </span>
                                <svg wire:loading.remove wire:target="verifyOtp" class="-ml-4 h-4 w-4 opacity-0 transition-all duration-500 group-hover:ml-0 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </span>
                            <div class="absolute inset-0 z-0 -translate-x-[150%] skew-x-12 bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-1000 ease-in-out group-hover:translate-x-[150%]"></div>
                        </button>

                        <button
                            type="button"
                            wire:click="resendOtp"
                            wire:loading.attr="disabled"
                            wire:target="resendOtp"
                            class="mt-3 w-full rounded-xl border border-stone-300 bg-white px-4 py-3 text-sm font-semibold text-stone-700 transition-colors hover:border-stone-400 hover:text-stone-900 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="resendOtp">Kirim Ulang OTP</span>
                            <span wire:loading wire:target="resendOtp">Mengirim ulang...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
