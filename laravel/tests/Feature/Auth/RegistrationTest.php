<?php

namespace Tests\Feature\Auth;

use App\Livewire\Chat\ChatIndex;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Services\Auth\PendingRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response
            ->assertRedirect('/login?view=register');

        $this->get('/login?view=register')
            ->assertOk()
            ->assertSeeVolt('pages.auth.login');
    }

    public function test_public_registration_can_be_disabled(): void
    {
        config()->set('auth.registration.enabled', false);
        Mail::fake();

        $this->get('/register')
            ->assertRedirect(route('login'));

        $this->get('/login?view=register')
            ->assertOk()
            ->assertDontSee('Daftar Sekarang', false)
            ->assertDontSee('Belum punya akun? Daftar', false);

        Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Blocked User')
            ->set('register_email', 'blocked@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['register_email']);

        Mail::assertQueued(VerificationCodeMail::class, 0);
        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    public function test_registration_rejects_email_header_injection_payload(): void
    {
        Mail::fake();

        $payload = "\"victim\r\nBcc:attacker@example.com\"@example.com";

        Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Header Injection User')
            ->set('register_email', $payload)
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['register_email']);

        Mail::assertQueued(VerificationCodeMail::class, 0);
        $this->assertDatabaseMissing('users', ['name' => 'Header Injection User']);
    }

    public function test_register_from_login_shows_verification_phase_without_creating_active_account(): void
    {
        Mail::fake();

        $component = Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Test User')
            ->set('register_email', 'test@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password');

        $component->call('register')
            ->assertSet('showVerificationModal', true)
            ->assertNoRedirect();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);

        Mail::assertQueued(VerificationCodeMail::class, fn (VerificationCodeMail $mail) => $mail->hasTo('test@example.com') && $mail->queue === 'mail' && $mail->tries === 1 && $mail->timeout === 15);
    }

    public function test_register_from_login_replaces_existing_unverified_account_and_sends_new_otp(): void
    {
        Mail::fake();

        User::factory()->unverified()->create([
            'name' => 'Legacy Pending User',
            'email' => 'replace@example.com',
        ]);

        $component = Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Replacement User')
            ->set('register_email', 'replace@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password');

        $component->call('register')
            ->assertSet('showVerificationModal', true)
            ->assertNoRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'replace@example.com']);

        Mail::assertQueued(VerificationCodeMail::class, fn (VerificationCodeMail $mail) => $mail->hasTo('replace@example.com') && $mail->queue === 'mail');
    }

    public function test_registration_start_is_rate_limited_before_sending_another_otp(): void
    {
        Mail::fake();
        $rateLimiter = app(PendingRegistrationService::class);
        $ipRateLimitKey = $rateLimiter->startRateLimitIpKey('127.0.0.1');
        $emailRateLimitKey = $rateLimiter->startRateLimitEmailKey('registration-start-limit@example.com');

        RateLimiter::clear($ipRateLimitKey);
        RateLimiter::clear($emailRateLimitKey);

        try {
            config([
                'auth.otp_registration.start_max_attempts' => 1,
                'auth.otp_registration.start_decay_seconds' => 600,
            ]);

            Volt::test('pages.auth.login')
                ->set('view', 'register')
                ->set('name', 'Rate Limited User')
                ->set('register_email', 'registration-start-limit@example.com')
                ->set('register_password', 'password')
                ->set('register_password_confirmation', 'password')
                ->call('register')
                ->assertSet('showVerificationModal', true)
                ->assertNoRedirect();

            Volt::test('pages.auth.login')
                ->set('view', 'register')
                ->set('name', 'Rate Limited User')
                ->set('register_email', 'registration-start-limit@example.com')
                ->set('register_password', 'password')
                ->set('register_password_confirmation', 'password')
                ->call('register')
                ->assertHasErrors(['register_email'])
                ->assertNoRedirect();

            Mail::assertQueued(VerificationCodeMail::class, 1);
            $this->assertDatabaseMissing('users', ['email' => 'registration-start-limit@example.com']);
        } finally {
            RateLimiter::clear($ipRateLimitKey);
            RateLimiter::clear($emailRateLimitKey);
        }
    }

    public function test_valid_otp_finalizes_registration_logs_in_and_redirects_to_intended_chat(): void
    {
        Mail::fake();

        $this->get('/guest-chat?q=tolong ringkas agenda hari ini')
            ->assertRedirect(route('login'));

        $component = Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Test User')
            ->set('register_email', 'test-register@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password');

        $component->call('register');

        $otpCode = null;
        Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$otpCode) {
            $otpCode = $mail->code;

            return $mail->hasTo('test-register@example.com') && $mail->queue === 'mail' && $mail->tries === 1 && $mail->timeout === 15;
        });

        $this->assertNotNull($otpCode);

        $component->set('verification_code_input', $otpCode)
            ->call('verifyOtp')
            ->assertRedirect(route('chat', absolute: false));

        $this->assertAuthenticated();

        $user = User::where('email', 'test-register@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('tolong ringkas agenda hari ini', session('pending_prompt'));

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->assertSet('prompt', 'tolong ringkas agenda hari ini');
    }

    public function test_cancel_verification_keeps_email_unregistered_and_reusable(): void
    {
        Mail::fake();
        Notification::fake();

        $component = Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Cancelled User')
            ->set('register_email', 'cancel@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password');

        $component->call('register')
            ->assertSet('showVerificationModal', true);

        $component->call('cancelVerification')
            ->assertSet('showVerificationModal', false);

        $this->assertDatabaseMissing('users', ['email' => 'cancel@example.com']);

        Volt::test('pages.auth.login')
            ->set('form.email', 'cancel@example.com')
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasErrors(['form.email']);

        Volt::test('pages.auth.forgot-password')
            ->set('email', 'cancel@example.com')
            ->call('sendPasswordResetLink');

        Notification::assertNothingSent();

        $component->set('name', 'Retry User')
            ->set('register_email', 'cancel@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password')
            ->call('register')
            ->assertSet('showVerificationModal', true);

        Mail::assertQueued(VerificationCodeMail::class, 2);
    }

    public function test_otp_attempts_are_rate_limited_after_multiple_failures(): void
    {
        Mail::fake();

        $component = Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Rate Limit User')
            ->set('register_email', 'rate-limit@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password');

        $component->call('register')
            ->assertSet('showVerificationModal', true)
            ->assertNoRedirect();

        $otpCode = null;
        Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$otpCode) {
            $otpCode = $mail->code;

            return $mail->hasTo('rate-limit@example.com') && $mail->queue === 'mail' && $mail->tries === 1 && $mail->timeout === 15;
        });

        $wrongCode = $otpCode === '000000' ? '999999' : '000000';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $component->set('verification_code_input', $wrongCode)
                ->call('verifyOtp')
                ->assertHasErrors(['verification_code_input']);
        }

        $component->set('verification_code_input', (string) $otpCode)
            ->call('verifyOtp')
            ->assertHasErrors(['verification_code_input'])
            ->assertNoRedirect();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'rate-limit@example.com']);
    }

    public function test_resend_otp_replaces_code_and_latest_code_can_be_used_for_verification(): void
    {
        Mail::fake();

        $component = Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Resend OTP User')
            ->set('register_email', 'resend@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password');

        $component->call('register')
            ->assertSet('showVerificationModal', true)
            ->assertNoRedirect();

        $initialQueuedMail = Mail::queued(VerificationCodeMail::class)->first();
        $initialOtpCode = $initialQueuedMail->code;
        $this->assertSame('mail', $initialQueuedMail->queue);
        $this->assertSame(1, $initialQueuedMail->tries);
        $this->assertSame(15, $initialQueuedMail->timeout);

        $component->call('resendOtp')
            ->assertSet('otp_status', 'Kode OTP baru telah dikirim ke email Anda.');

        Mail::assertQueued(VerificationCodeMail::class, 2);

        $resentQueuedMail = Mail::queued(VerificationCodeMail::class)->last();
        $resentOtpCode = $resentQueuedMail->code;
        $this->assertSame('mail', $resentQueuedMail->queue);
        $this->assertSame(1, $resentQueuedMail->tries);
        $this->assertSame(15, $resentQueuedMail->timeout);

        $this->assertNotSame($initialOtpCode, $resentOtpCode);

        $component->set('verification_code_input', (string) $initialOtpCode)
            ->call('verifyOtp')
            ->assertHasErrors(['verification_code_input'])
            ->assertNoRedirect();

        $component->set('verification_code_input', (string) $resentOtpCode)
            ->call('verifyOtp')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'resend@example.com']);
    }

    public function test_resend_otp_is_rate_limited_by_cooldown(): void
    {
        Mail::fake();

        $component = Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Resend Cooldown User')
            ->set('register_email', 'resend-cooldown@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password');

        $component->call('register')
            ->assertSet('showVerificationModal', true)
            ->assertNoRedirect();

        $component->call('resendOtp')
            ->assertSet('otp_status', 'Kode OTP baru telah dikirim ke email Anda.');

        $component->call('resendOtp')
            ->assertHasErrors(['verification_code_input'])
            ->assertNoRedirect();

        Mail::assertQueued(VerificationCodeMail::class, 2);
        $this->assertGuest();
    }

    public function test_registration_requires_valid_fields_and_duplicate_email_is_rejected_in_indonesian(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
            'email_verified_at' => now(),
        ]);

        Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', '')
            ->set('register_email', 'not-an-email')
            ->set('register_password', '')
            ->set('register_password_confirmation', '')
            ->call('register')
            ->assertHasErrors([
                'name' => 'Kolom nama wajib diisi.',
                'register_email' => 'Kolom email harus berupa alamat email yang valid.',
                'register_password' => 'Kolom kata sandi wajib diisi.',
            ]);

        Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Test User')
            ->set('register_email', 'existing@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors([
                'register_email' => 'Kolom email sudah digunakan.',
            ]);
    }

    public function test_register_confirmation_error_is_shown_and_indonesian(): void
    {
        Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Test User')
            ->set('register_email', 'confirm-test@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'different-password')
            ->call('register')
            ->assertHasErrors(['register_password' => 'kata sandi tidak cocok.']);
    }

    public function test_register_confirmation_error_is_indonesian_when_locale_is_en(): void
    {
        // Force app locale to 'en' before Livewire component mount to simulate production
        app()->setLocale('en');

        Volt::test('pages.auth.login')
            ->set('view', 'register')
            ->set('name', 'Test User')
            ->set('register_email', 'confirm-test-2@example.com')
            ->set('register_password', 'password')
            ->set('register_password_confirmation', 'different-password')
            ->call('register')
            ->assertHasErrors(['register_password' => 'kata sandi tidak cocok.']);
    }
}
