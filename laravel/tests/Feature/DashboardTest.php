<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_can_be_rendered(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Tanya');
        $response->assertSee('Buka Chat', false);
        $response->assertSee('Buka Memo', false);
        $response->assertSee(route('guest-memo'), false);
    }

    public function test_dashboard_keeps_header_main_and_footer_inside_viewport(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('min-h-[var(--app-viewport-height)]', false);
        $response->assertSee('flex-none', false);
        $response->assertSee('flex-1', false);
        $response->assertSee('All Rights Reserved.', false);
        $response->assertSee('x-show="darkMode === true" style="display: none;"', false);
    }

    public function test_guest_chat_redirects_to_login_and_saves_prompt(): void
    {
        $response = $this->get('/guest-chat?q=hello');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('pending_prompt', 'hello');
        $response->assertSessionHas('url.intended', route('chat'));
    }

    public function test_dashboard_chat_requires_auth(): void
    {
        $response = $this->get('/chat');
        $response->assertRedirect('/login');
    }

    public function test_guest_memo_redirects_to_login_and_saves_memo_tab_intended_url(): void
    {
        $response = $this->get('/guest-memo');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('url.intended', route('chat', ['tab' => 'memo']));
    }

    public function test_authenticated_dashboard_links_to_memo_tab(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertSee(route('chat'), false);
        $response->assertSee(route('chat', ['tab' => 'memo']), false);
    }

    public function test_authenticated_dashboard_links_to_prompy_when_feature_is_enabled(): void
    {
        config(['features.prompy' => true]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertSee('Buka Prompy', false);
        $response->assertSee(route('chat', ['tab' => 'prompy']), false);
        $response->assertSee('sm:grid-cols-3', false);
    }

    public function test_authenticated_dashboard_hides_prompy_when_feature_is_disabled(): void
    {
        config(['features.prompy' => false]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('Buka Prompy', false);
        $response->assertDontSee(route('chat', ['tab' => 'prompy']), false);
        $response->assertSee('sm:grid-cols-2', false);
    }
}
