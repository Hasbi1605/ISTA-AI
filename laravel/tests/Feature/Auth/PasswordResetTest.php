<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\CustomResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response
            ->assertSeeVolt('pages.auth.forgot-password')
            ->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, CustomResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, CustomResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response
                ->assertSeeVolt('pages.auth.reset-password')
                ->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, CustomResetPassword::class, function ($notification) use ($user) {
            $component = Volt::test('pages.auth.reset-password', ['token' => $notification->token])
                ->set('email', $user->email)
                ->set('password', 'password')
                ->set('password_confirmation', 'password');

            $component->call('resetPassword');

            $component
                ->assertRedirect('/login')
                ->assertSessionHas('status', 'Kata sandi Anda telah berhasil diatur ulang. Silakan masuk.')
                ->assertHasNoErrors();

            return true;
        });
    }

    public function test_unverified_user_cannot_request_password_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink')
            ->assertHasErrors(['email' => 'Email belum terverifikasi. Verifikasi akun dengan OTP terbaru sebelum meminta reset kata sandi.']);

        Notification::assertNothingSent();
    }

    public function test_password_reset_link_requires_registered_email_in_indonesian(): void
    {
        Notification::fake();

        Volt::test('pages.auth.forgot-password')
            ->set('email', 'missing@example.com')
            ->call('sendPasswordResetLink')
            ->assertHasErrors(['email' => 'Kami tidak dapat menemukan pengguna dengan alamat email tersebut.']);

        Notification::assertNothingSent();
    }

    public function test_reset_password_fails_with_invalid_token_in_indonesian(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.reset-password', ['token' => 'invalid-token'])
            ->set('email', $user->email)
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('resetPassword')
            ->assertHasErrors(['email' => 'Token reset kata sandi tidak valid.']);
    }

    public function test_reset_password_requires_fields_in_indonesian(): void
    {
        Notification::fake();

        Volt::test('pages.auth.reset-password', ['token' => ''])
            ->set('email', '')
            ->set('password', '')
            ->set('password_confirmation', '')
            ->call('resetPassword')
            ->assertHasErrors([
                'token' => 'Kolom token wajib diisi.',
                'email' => 'Kolom email wajib diisi.',
                'password' => 'Kolom kata sandi wajib diisi.',
            ]);
    }

    public function test_forgot_password_error_is_indonesian_when_locale_is_en(): void
    {
        Notification::fake();

        Lang::setLocale('en');

        Volt::test('pages.auth.forgot-password')
            ->set('email', 'missing@example.com')
            ->call('sendPasswordResetLink')
            ->assertHasErrors(['email' => 'Kami tidak dapat menemukan pengguna dengan alamat email tersebut.']);

        Notification::assertNothingSent();
    }

    public function test_reset_password_invalid_token_error_is_indonesian_when_locale_is_en(): void
    {
        $user = User::factory()->create();

        Lang::setLocale('en');

        Volt::test('pages.auth.reset-password', ['token' => 'invalid-token'])
            ->set('email', $user->email)
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('resetPassword')
            ->assertHasErrors(['email' => 'Token reset kata sandi tidak valid.']);
    }
}
