<?php

use App\Livewire\Forms\LoginForm;
use App\Models\User;
use App\Rules\NoEmailHeaderInjection;
use App\Services\Auth\PasswordResetLinkService;
use App\Services\Auth\PendingRegistrationWorkflowService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
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
        if (request()->query('view') === 'register' && $this->registrationEnabled()) {
            $this->view = 'register';
        }
    }

    protected function registrationEnabled(): bool
    {
        return (bool) config('auth.registration.enabled');
    }

    protected function passwordResetLinkService(): PasswordResetLinkService
    {
        return app(PasswordResetLinkService::class);
    }

    protected function pendingRegistrationWorkflowService(): PendingRegistrationWorkflowService
    {
        return app(PendingRegistrationWorkflowService::class);
    }

    public function setView(string $view): void
    {
        if ($view === 'register' && ! $this->registrationEnabled()) {
            $view = 'login';
        }

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
            'forgot_email' => ['required', 'email', new NoEmailHeaderInjection],
        ], [], [
            'forgot_email' => 'email',
        ]);

        $this->forgot_status = $this->passwordResetLinkService()->sendResetLink($this->forgot_email, 'forgot_email');
        $this->forgot_email = '';
    }

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate([
            'form.email' => ['required', 'string', 'email', new NoEmailHeaderInjection],
            'form.password' => 'required|string',
        ]);

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function register(): void
    {
        if (! $this->registrationEnabled()) {
            throw ValidationException::withMessages([
                'register_email' => 'Pendaftaran mandiri sedang ditutup. Hubungi admin untuk membuat akun.',
            ]);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'register_email' => [
                'required',
                'string',
                'lowercase',
                'email',
                new NoEmailHeaderInjection,
                'max:255',
                Rule::unique(User::class, 'email')
                    ->where(fn ($query) => $query->whereNotNull('email_verified_at')),
            ],
            'register_password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $this->pendingRegistrationToken = $this->pendingRegistrationWorkflowService()->startRegistration(
            name: $validated['name'],
            email: $validated['register_email'],
            password: $validated['register_password'],
            ipAddress: request()->ip(),
        );

        $this->showVerificationModal = true;
        $this->verification_code_input = '';
        $this->otp_status = null;
    }

    public function resendOtp(): void
    {
        if (! $this->registrationEnabled()) {
            throw ValidationException::withMessages([
                'verification_code_input' => 'Pendaftaran mandiri sedang ditutup. Hubungi admin untuk membuat akun.',
            ]);
        }

        $this->pendingRegistrationWorkflowService()->resendOtp(
            $this->pendingRegistrationToken,
            request()->ip(),
        );

        $this->verification_code_input = '';
        $this->otp_status = 'Kode OTP baru telah dikirim ke email Anda.';
    }

    public function cancelVerification(): void
    {
        if (! $this->pendingRegistrationToken) {
            $this->showVerificationModal = false;

            return;
        }

        $this->pendingRegistrationWorkflowService()->cancelRegistration(
            $this->pendingRegistrationToken,
            request()->ip(),
        );

        $this->pendingRegistrationToken = null;
        $this->verification_code_input = '';
        $this->showVerificationModal = false;
        $this->otp_status = null;
    }

    public function verifyOtp(): void
    {
        if (! $this->registrationEnabled()) {
            $this->addError('verification_code_input', 'Pendaftaran mandiri sedang ditutup. Hubungi admin untuk membuat akun.');

            return;
        }

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

        try {
            $user = $this->pendingRegistrationWorkflowService()->verifyOtp(
                $this->pendingRegistrationToken,
                $this->verification_code_input,
                request()->ip(),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->addError('verification_code_input', 'Terjadi kendala saat menyelesaikan pendaftaran. Silakan coba lagi.');

            return;
        }

        Auth::login($user);
        Session::regenerate();

        $this->pendingRegistrationToken = null;
        $this->verification_code_input = '';
        $this->showVerificationModal = false;
        $this->otp_status = null;

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="relative flex min-h-screen w-full items-center justify-center overflow-hidden bg-[#fafaf9] transition-colors duration-300 dark:bg-gray-950">
    @include('livewire.pages.auth.partials.auth-background')

    @include('livewire.pages.auth.partials.auth-card')

    @if($showVerificationModal)
        @include('livewire.pages.auth.partials.otp-verification-modal')
    @endif
</div>
