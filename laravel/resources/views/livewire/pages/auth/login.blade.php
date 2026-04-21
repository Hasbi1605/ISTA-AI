<?php

use App\Livewire\Forms\LoginForm;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Services\Auth\PendingRegistrationService;
use Illuminate\Support\Facades\Auth;
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

    protected function pendingRegistrationService(): PendingRegistrationService
    {
        return app(PendingRegistrationService::class);
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
        $pendingRegistrationService = $this->pendingRegistrationService();

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
        $existingPendingToken = $pendingRegistrationService->pendingTokenByEmail($registrationEmail);
        if ($existingPendingToken) {
            $pendingRegistrationService->clearPendingRegistration($existingPendingToken, $registrationEmail, request()->ip());
        }

        [$pendingToken, $plainCode] = $pendingRegistrationService->createPendingRegistration(
            name: $validated['name'],
            email: $registrationEmail,
            hashedPassword: Hash::make($validated['register_password']),
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

        $pendingRegistrationService = $this->pendingRegistrationService();
        $pending = $pendingRegistrationService->getPendingRegistration($this->pendingRegistrationToken);

        if (! is_array($pending)) {
            $this->addError('verification_code_input', 'Sesi pendaftaran sudah berakhir. Silakan daftar ulang.');

            return;
        }

        $otpResendRateLimitKey = $pendingRegistrationService->otpResendRateLimitKey(
            token: $this->pendingRegistrationToken,
            ipAddress: request()->ip(),
        );

        if (RateLimiter::tooManyAttempts($otpResendRateLimitKey, 1)) {
            $seconds = RateLimiter::availableIn($otpResendRateLimitKey);
            $this->addError('verification_code_input', 'Kode OTP sudah dikirim ulang. Coba lagi dalam '.$seconds.' detik.');

            return;
        }

        $plainCode = sprintf('%06d', random_int(0, 999999));
        $email = (string) ($pending['email'] ?? '');

        $pendingRegistrationService->storePendingRegistration($this->pendingRegistrationToken, [
            'name' => (string) ($pending['name'] ?? ''),
            'email' => $email,
            'password' => (string) ($pending['password'] ?? ''),
            'code_hash' => hash('sha256', $plainCode),
        ]);

        Mail::to($email)->send(new VerificationCodeMail($plainCode));

        RateLimiter::hit($otpResendRateLimitKey, $pendingRegistrationService->otpResendCooldownSeconds());

        $this->verification_code_input = '';
        $this->otp_status = 'Kode OTP baru telah dikirim ke email Anda.';
    }

    public function cancelVerification(): void
    {
        if (! $this->pendingRegistrationToken) {
            $this->showVerificationModal = false;

            return;
        }

        $pendingRegistrationService = $this->pendingRegistrationService();
        $pending = $pendingRegistrationService->getPendingRegistration($this->pendingRegistrationToken);
        $pendingEmail = is_array($pending) ? ($pending['email'] ?? null) : null;

        $pendingRegistrationService->clearPendingRegistration($this->pendingRegistrationToken, $pendingEmail, request()->ip());

        $this->pendingRegistrationToken = null;
        $this->verification_code_input = '';
        $this->showVerificationModal = false;
        $this->otp_status = null;
    }

    public function verifyOtp(): void
    {
        $pendingRegistrationService = $this->pendingRegistrationService();

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

        $otpRateLimitKey = $pendingRegistrationService->otpRateLimitKey(
            token: $this->pendingRegistrationToken,
            ipAddress: request()->ip(),
        );
        if (RateLimiter::tooManyAttempts($otpRateLimitKey, $pendingRegistrationService->otpMaxAttempts())) {
            $seconds = RateLimiter::availableIn($otpRateLimitKey);
            $this->addError('verification_code_input', 'Terlalu banyak percobaan OTP. Coba lagi dalam '.ceil($seconds / 60).' menit.');

            return;
        }

        $pending = $pendingRegistrationService->getPendingRegistration($this->pendingRegistrationToken);

        if (! is_array($pending)) {
            $this->addError('verification_code_input', 'Sesi pendaftaran sudah berakhir. Silakan daftar ulang.');

            return;
        }

        $expiresAt = (int) ($pending['expires_at'] ?? 0);
        if ($expiresAt < now()->getTimestamp()) {
            $pendingRegistrationService->clearPendingRegistration($this->pendingRegistrationToken, $pending['email'] ?? null, request()->ip());
            $this->addError('verification_code_input', 'Kode verifikasi sudah kedaluwarsa. Silakan daftar ulang.');

            return;
        }

        $providedCodeHash = hash('sha256', $this->verification_code_input);

        if (! hash_equals((string) ($pending['code_hash'] ?? ''), $providedCodeHash)) {
            RateLimiter::hit($otpRateLimitKey, $pendingRegistrationService->otpDecaySeconds());

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

        RateLimiter::clear($otpRateLimitKey);

        Auth::login($user);

        Session::regenerate();

        $pendingRegistrationService->clearPendingRegistration($this->pendingRegistrationToken, $email, request()->ip());
        $this->pendingRegistrationToken = null;
        $this->verification_code_input = '';
        $this->showVerificationModal = false;
        $this->otp_status = null;

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="relative flex min-h-screen w-full items-center justify-center overflow-hidden bg-[#fafaf9]">
    @include('livewire.pages.auth.partials.auth-background')

    @include('livewire.pages.auth.partials.auth-card')

    @if($showVerificationModal)
        @include('livewire.pages.auth.partials.otp-verification-modal')
    @endif
</div>
