<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Notifications\CustomResetPassword;
use App\Services\Auth\PasswordResetLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PasswordResetLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_is_rejected_on_custom_error_field(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        try {
            app(PasswordResetLinkService::class)->sendResetLink($user->email, 'forgot_email');
            $this->fail('Expected the service to reject unverified users.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('forgot_email', $e->errors());
            $this->assertSame(
                'Email belum terverifikasi. Verifikasi akun dengan OTP terbaru sebelum meminta reset kata sandi.',
                $e->errors()['forgot_email'][0],
            );
        }

        Notification::assertNothingSent();
    }

    public function test_verified_user_receives_reset_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $status = app(PasswordResetLinkService::class)->sendResetLink($user->email, 'email');

        $this->assertNotEmpty($status);
        Notification::assertSentTo($user, CustomResetPassword::class);
    }
}
