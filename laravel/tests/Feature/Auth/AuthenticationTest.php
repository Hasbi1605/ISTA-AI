<?php

namespace Tests\Feature\Auth;

use App\Livewire\Chat\ChatIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.login');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_inactive_admin_cannot_authenticate_using_the_regular_login_screen(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => false,
        ]);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $admin->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasErrors(['form.email' => 'Akun sedang dinonaktifkan. Hubungi admin.'])
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_users_are_redirected_to_chat_after_logging_in_from_guest_chat_flow(): void
    {
        $user = User::factory()->create();

        $this->get('/guest-chat')
            ->assertRedirect(route('login'));

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('chat', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_dashboard_prompt_is_preserved_until_chat_mount_after_guest_login(): void
    {
        $user = User::factory()->create();

        $this->get('/guest-chat?q=ringkas berita hari ini')
            ->assertRedirect(route('login'));

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('chat', absolute: false));

        $this->assertAuthenticated();
        $this->assertSame('ringkas berita hari ini', session('pending_prompt'));

        Livewire::actingAs($user)
            ->test(ChatIndex::class)
            ->assertSet('prompt', 'ringkas berita hari ini');
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password');

        $component->call('login');

        $component
            ->assertHasErrors(['form.email' => 'Email atau kata sandi tidak sesuai dengan data kami.'])
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_login_requires_email_and_password_in_indonesian(): void
    {
        $component = Volt::test('pages.auth.login')
            ->set('form.email', '')
            ->set('form.password', '')
            ->call('login');

        $component
            ->assertHasErrors([
                'form.email' => 'Kolom email wajib diisi.',
                'form.password' => 'Kolom kata sandi wajib diisi.',
            ])
            ->assertNoRedirect();
    }

    public function test_unverified_users_cannot_authenticate_using_login_screen(): void
    {
        $user = User::factory()->unverified()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasErrors(['form.email' => 'Login gagal. Akun belum terverifikasi. Gunakan OTP verifikasi terbaru atau minta OTP baru dari halaman verifikasi email.'])
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_login_throttle_message_is_indonesian(): void
    {
        $user = User::factory()->create();

        $this->freezeTime(function () use ($user): void {
            $throttleKey = Str::transliterate(Str::lower($user->email).'|127.0.0.1');

            for ($attempt = 0; $attempt < 5; $attempt++) {
                RateLimiter::hit($throttleKey, 120);
            }

            $seconds = RateLimiter::availableIn($throttleKey);

            try {
                Volt::test('pages.auth.login')
                    ->set('form.email', $user->email)
                    ->set('form.password', 'password')
                    ->call('login')
                    ->assertHasErrors([
                        'form.email' => "Terlalu banyak percobaan login. Silakan coba lagi dalam {$seconds} detik.",
                    ]);
            } finally {
                RateLimiter::clear($throttleKey);
            }
        });
    }

    public function test_chat_page_can_be_rendered_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get('/chat');

        $response
            ->assertOk()
            ->assertSee('ISTA AI');
    }

    public function test_inactive_authenticated_admin_is_logged_out_from_chat(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->get('/chat');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('layout.navigation');

        $component->call('logout');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_auth_error_messages_are_indonesian_when_locale_is_en(): void
    {
        $user = User::factory()->create();

        Lang::setLocale('en');

        $component = Volt::test('pages.auth.login')
            ->set('form.email', 'wrong@email.com')
            ->set('form.password', 'wrong-password');

        $component->call('login');

        $component
            ->assertHasErrors(['form.email' => 'Email atau kata sandi tidak sesuai dengan data kami.'])
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_auth_validation_errors_are_indonesian_when_locale_is_en(): void
    {
        Lang::setLocale('en');

        $component = Volt::test('pages.auth.login')
            ->set('form.email', '')
            ->set('form.password', '')
            ->call('login');

        $component
            ->assertHasErrors([
                'form.email' => 'Kolom email wajib diisi.',
                'form.password' => 'Kolom kata sandi wajib diisi.',
            ])
            ->assertNoRedirect();
    }
}
