<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\AdminUsage;
use App\Livewire\Admin\AdminUsers;
use App\Models\AIUsageEvent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Memo;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AdminMonitoringDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_overview_shows_kpi_cards_chart_and_recent_event_table(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'last_seen_at' => $now->copy()->subSeconds(15),
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'last_seen_at' => $now->copy()->subMinutes(7),
        ]);

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHour(), 'req-1', 1200);
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_DOCUMENT_RAG, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subMinutes(15), 'req-2', 700, 'rag_timeout');

        $response = $this->actingAs($admin)->get('/admin');
        $response->assertOk();
        $response->assertSee('Ringkasan Operasional', false);
        $response->assertSee('Aktivitas Terbaru', false);
        $response->assertSee('Distribusi Fitur', false);
        $response->assertSee('Error Terbaru', false);
        $response->assertSee('chat', false);
        $response->assertSee('document_rag', false);
        $response->assertSee('rag_timeout', false);
        $response->assertSee('admin-kpi', false);

        Carbon::setTestNow();
    }

    public function test_admin_users_page_shows_presence_status_for_each_user(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'last_seen_at' => $now,
            'name' => 'Admin Aktif',
        ]);

        User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'User Idle',
            'last_seen_at' => $now->copy()->subMinutes(8),
        ]);

        User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'User Offline',
            'last_seen_at' => $now->copy()->subDays(2),
        ]);

        $response = $this->actingAs($admin)->get('/admin/users');
        $response->assertOk();
        $response->assertSee('User & Presence', false);
        $response->assertSee('Admin Aktif', false);
        $response->assertSee('User Idle', false);
        $response->assertSee('User Offline', false);
        $response->assertSee('Online', false);
        $response->assertSee('Idle', false);
        $response->assertSee('Offline', false);

        Carbon::setTestNow();
    }

    public function test_admin_users_filter_by_status_returns_only_online_users(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'last_seen_at' => $now,
            'name' => 'Admin Aktif',
        ]);

        User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Idle Person',
            'last_seen_at' => $now->copy()->subMinutes(8),
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->set('status', 'online')
            ->assertSee('Admin Aktif')
            ->assertDontSee('Idle Person');

        Carbon::setTestNow();
    }

    public function test_admin_usage_filter_by_feature(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHour(), 'req-chat');
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_DOCUMENT_RAG, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subMinutes(30), 'req-rag');

        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->set('feature', AIUsageEvent::FEATURE_CHAT)
            ->assertSee('req-chat', false)
            ->assertDontSee('req-rag', false);

        Carbon::setTestNow();
    }

    public function test_admin_usage_end_date_filter_includes_the_full_selected_day(): void
    {
        $now = Carbon::parse('2026-05-20 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, Carbon::parse('2026-05-18 23:45:00'), 'req-end-day');
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, Carbon::parse('2026-05-19 00:01:00'), 'req-next-day');

        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->set('startDate', '2026-05-18')
            ->set('endDate', '2026-05-18')
            ->assertSee('req-end-day', false)
            ->assertDontSee('req-next-day', false);

        Carbon::setTestNow();
    }

    public function test_admin_errors_page_only_shows_failed_or_blocked_events(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHour(), 'req-success');
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subMinutes(30), 'req-fail', null, 'rate_limited');

        $response = $this->actingAs($admin)->get('/admin/errors');
        $response->assertOk();
        $response->assertSee('rate_limited', false);
        $response->assertDontSee('req-success', false);

        Carbon::setTestNow();
    }

    public function test_admin_documents_page_shows_status_kpis(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_USER]);

        Document::create([
            'user_id' => $owner->id,
            'filename' => 'a.pdf',
            'original_name' => 'a.pdf',
            'file_path' => 'docs/a.pdf',
            'status' => 'ready',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
        ]);

        Document::create([
            'user_id' => $owner->id,
            'filename' => 'b.pdf',
            'original_name' => 'b.pdf',
            'file_path' => 'docs/b.pdf',
            'status' => 'error',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
        ]);

        $response = $this->actingAs($admin)->get('/admin/documents');
        $response->assertOk();
        $response->assertSee('Dokumen User', false);
        $response->assertSee('a.pdf', false);
        $response->assertSee('b.pdf', false);
        $response->assertSee('Ready', false);
        $response->assertSee('Error', false);
    }

    public function test_regular_user_cannot_access_monitoring_pages(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)->get('/admin/users')->assertStatus(403);
        $this->actingAs($user)->get('/admin/usage')->assertStatus(403);
        $this->actingAs($user)->get('/admin/errors')->assertStatus(403);
        $this->actingAs($user)->get('/admin/documents')->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login_for_monitoring_pages(): void
    {
        $this->get('/admin/users')->assertRedirect(route('login'));
        $this->get('/admin/usage')->assertRedirect(route('login'));
        $this->get('/admin/errors')->assertRedirect(route('login'));
        $this->get('/admin/documents')->assertRedirect(route('login'));
    }

    public function test_admin_pages_do_not_leak_message_content(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $secret = 'SECRET_PROMPT_NOT_FOR_DASHBOARD_'.uniqid();

        $conv = Conversation::create([
            'user_id' => $user->id,
            'title' => $secret,
        ]);

        Message::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => $secret,
        ]);

        AIUsageEvent::create([
            'user_id' => $user->id,
            'feature' => AIUsageEvent::FEATURE_CHAT,
            'action' => AIUsageEvent::ACTION_COMPLETED,
            'status' => AIUsageEvent::STATUS_SUCCESS,
            'request_id' => 'req-no-leak',
            'metadata' => null,
        ]);

        Memo::create([
            'user_id' => $user->id,
            'title' => $secret,
            'memo_type' => 'memo_internal',
            'status' => Memo::STATUS_DRAFT,
            'searchable_text' => $secret,
        ]);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee($secret);
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertDontSee($secret);
        $this->actingAs($admin)->get('/admin/usage')->assertOk()->assertDontSee($secret);
        $this->actingAs($admin)->get('/admin/errors')->assertOk()->assertDontSee($secret);
        $this->actingAs($admin)->get('/admin/documents')->assertOk()->assertDontSee($secret);

        Carbon::setTestNow();
    }

    public function test_admin_sidebar_shows_monitoring_menu_links(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get('/admin');
        $response->assertOk();
        $response->assertSee(route('admin.users'), false);
        $response->assertSee(route('admin.usage'), false);
        $response->assertSee(route('admin.errors'), false);
        $response->assertSee(route('admin.documents'), false);
    }

    public function test_admin_usage_handles_malformed_date_query_strings_gracefully(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Malformed start/end dates from the query string must not raise
        // a 500. The page should render normally and ignore the invalid
        // values rather than passing them into Carbon::parse().
        $response = $this->actingAs($admin)->get('/admin/usage?startDate=not-a-date&endDate=also-bad');
        $response->assertOk();
        $response->assertSee('AI Usage Events', false);

        // Empty values must also be tolerated.
        $response = $this->actingAs($admin)->get('/admin/usage?startDate=&endDate=');
        $response->assertOk();
        $response->assertSee('AI Usage Events', false);
    }

    public function test_admin_usage_livewire_component_handles_invalid_date_input(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);

        Livewire::test(AdminUsage::class)
            ->set('startDate', 'banana')
            ->set('endDate', 'pineapple')
            ->assertOk()
            ->assertSee('AI Usage Events');
    }

    private function makeEvent(int $userId, string $feature, string $action, string $status, Carbon $createdAt, ?string $requestId = null, ?int $latencyMs = null, ?string $errorCode = null): AIUsageEvent
    {
        $event = new AIUsageEvent([
            'user_id' => $userId,
            'feature' => $feature,
            'action' => $action,
            'status' => $status,
            'request_id' => $requestId,
            'latency_ms' => $latencyMs,
            'error_code' => $errorCode,
        ]);
        $event->timestamps = false;
        $event->created_at = $createdAt;
        $event->updated_at = $createdAt;
        $event->save();

        return $event;
    }
}
