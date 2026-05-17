<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSeeVolt('profile.delete-user-form');
    }

    public function test_profile_page_shows_return_to_chat_link_when_opened_from_chat(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile?from=chat')
            ->assertOk()
            ->assertSee('Kembali ke Chat', false)
            ->assertSee(route('chat', ['tab' => 'chat']), false)
            ->assertDontSee('Kembali ke Memo', false);
    }

    public function test_profile_page_shows_return_to_memo_link_when_opened_from_memo(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile?from=memo')
            ->assertOk()
            ->assertSee('Kembali ke Memo', false)
            ->assertSee(route('chat', ['tab' => 'memo']), false)
            ->assertDontSee('Kembali ke Chat', false);
    }

    public function test_profile_page_hides_contextual_return_link_without_valid_source(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile?from=https://example.com')
            ->assertOk()
            ->assertDontSee('Kembali ke Chat', false)
            ->assertDontSee('Kembali ke Memo', false);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-profile-information-form')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $component
            ->assertHasErrors(['password' => 'Kata sandi yang dimasukkan salah.'])
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
    }

    public function test_profile_email_must_be_valid_and_unique_in_indonesian(): void
    {
        $user = User::factory()->create([
            'email' => 'primary@example.com',
        ]);

        User::factory()->create([
            'email' => 'taken@example.com',
        ]);

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'User Test')
            ->set('email', 'invalid-email')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email' => 'Kolom email harus berupa alamat email yang valid.']);

        Volt::test('profile.update-profile-information-form')
            ->set('name', 'User Test')
            ->set('email', 'taken@example.com')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email' => 'Kolom email sudah digunakan.']);
    }
}
