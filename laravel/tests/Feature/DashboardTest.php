<?php

namespace Tests\Feature;

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
}
