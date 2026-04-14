<?php

namespace Tests\Feature\Auth;

use App\Mail\VerificationCodeMail;
use App\Livewire\Chat\ChatIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
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

        Mail::assertSent(VerificationCodeMail::class, fn (VerificationCodeMail $mail) => $mail->hasTo('test@example.com'));
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
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$otpCode) {
            $otpCode = $mail->code;

            return $mail->hasTo('test-register@example.com');
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

        Mail::assertSent(VerificationCodeMail::class, 2);
    }
}
