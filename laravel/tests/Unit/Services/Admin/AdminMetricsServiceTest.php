<?php

namespace Tests\Unit\Services\Admin;

use App\Models\AIUsageEvent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Memo;
use App\Models\Message;
use App\Models\User;
use App\Services\Admin\AdminMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdminMetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new AdminMetricsService;
    }

    public function test_overview_kpis_aggregate_users_events_documents_and_memos(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'last_seen_at' => $now->copy()->subSeconds(30),
        ]);

        $idle = User::factory()->create([
            'role' => User::ROLE_USER,
            'last_seen_at' => $now->copy()->subMinutes(5),
        ]);

        $offline = User::factory()->create([
            'role' => User::ROLE_USER,
            'last_seen_at' => $now->copy()->subDays(3),
        ]);

        // events today
        $this->makeEvent($admin->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHours(2), 1000);
        $this->makeEvent($idle->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subHours(1), 500, 'upstream_timeout');
        $this->makeEvent($admin->id, AIUsageEvent::FEATURE_DOCUMENT_RAG, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subMinutes(30), 2000);

        // event from older day (should not count today)
        $this->makeEvent($offline->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subDays(2), 9999);

        // conversation/messages today
        $conv = Conversation::create([
            'user_id' => $admin->id,
            'title' => 'Test',
        ]);
        Message::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => 'hi',
        ]);
        Message::create([
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'content' => 'hello',
        ]);

        // documents
        Document::create([
            'user_id' => $admin->id,
            'filename' => 'a.pdf',
            'original_name' => 'a.pdf',
            'file_path' => 'docs/a.pdf',
            'status' => 'ready',
        ]);
        Document::create([
            'user_id' => $admin->id,
            'filename' => 'b.pdf',
            'original_name' => 'b.pdf',
            'file_path' => 'docs/b.pdf',
            'status' => 'processing',
        ]);
        Document::create([
            'user_id' => $admin->id,
            'filename' => 'c.pdf',
            'original_name' => 'c.pdf',
            'file_path' => 'docs/c.pdf',
            'status' => 'error',
        ]);

        // memos today
        Memo::create([
            'user_id' => $admin->id,
            'title' => 'memo today',
            'memo_type' => 'memo_internal',
            'status' => Memo::STATUS_DRAFT,
        ]);

        $kpis = $this->metrics->overviewKpis($now);

        $this->assertSame(3, $kpis['total_users']);
        $this->assertSame(1, $kpis['online_users']);
        $this->assertSame(1, $kpis['idle_users']);
        $this->assertSame(3, $kpis['ai_requests_today']);
        $this->assertSame(2, $kpis['ai_success_today']);
        $this->assertSame(1, $kpis['ai_failed_today']);
        $this->assertSame(0, $kpis['ai_pending_today']);
        // avg of 1000 and 2000 (success only)
        $this->assertSame(1500, $kpis['avg_latency_ms_today']);
        $this->assertSame(1, $kpis['conversations_today']);
        $this->assertSame(1, $kpis['messages_today']); // user role only
        $this->assertSame(1, $kpis['documents_ready']);
        $this->assertSame(1, $kpis['documents_processing']);
        $this->assertSame(1, $kpis['documents_failed']);
        $this->assertSame(1, $kpis['memos_today']);
        $this->assertSame(1, $kpis['memos_week']);
        // active_users_today should include admin (online) and idle (presence today)
        $this->assertGreaterThanOrEqual(2, $kpis['active_users_today']);

        Carbon::setTestNow();
    }

    private function makeEvent(int $userId, string $feature, string $action, string $status, Carbon $createdAt, ?int $latencyMs = null, ?string $errorCode = null): AIUsageEvent
    {
        $event = new AIUsageEvent([
            'user_id' => $userId,
            'feature' => $feature,
            'action' => $action,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'error_code' => $errorCode,
        ]);
        $event->timestamps = false;
        $event->created_at = $createdAt;
        $event->updated_at = $createdAt;
        $event->save();

        return $event;
    }

    public function test_presence_status_returns_online_idle_offline(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');

        $online = new User(['last_seen_at' => $now->copy()->subSeconds(30)]);
        $online->setRawAttributes(['last_seen_at' => $now->copy()->subSeconds(30)]);

        $idle = new User(['last_seen_at' => $now->copy()->subMinutes(5)]);
        $idle->setRawAttributes(['last_seen_at' => $now->copy()->subMinutes(5)]);

        $offline = new User(['last_seen_at' => $now->copy()->subHour()]);
        $offline->setRawAttributes(['last_seen_at' => $now->copy()->subHour()]);

        $never = new User;

        $this->assertSame('online', $this->metrics->presenceStatus($online, $now));
        $this->assertSame('idle', $this->metrics->presenceStatus($idle, $now));
        $this->assertSame('offline', $this->metrics->presenceStatus($offline, $now));
        $this->assertSame('offline', $this->metrics->presenceStatus($never, $now));
    }

    public function test_daily_activity_series_returns_window_with_zeroed_days(): void
    {
        $now = Carbon::parse('2026-05-18 23:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->startOfDay());
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subDays(2)->startOfDay());

        $series = $this->metrics->dailyActivitySeries(7, $now);

        $this->assertCount(7, $series);
        $this->assertSame(1, collect($series)->where('date', $now->toDateString())->first()['success']);
        $this->assertSame(1, collect($series)->where('date', $now->copy()->subDays(2)->toDateString())->first()['failed']);
        // Window total
        $this->assertSame(2, collect($series)->sum('total'));

        Carbon::setTestNow();
    }

    public function test_recent_events_applies_filters(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHours(1));
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_DOCUMENT_RAG, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subHours(2));

        $rows = $this->metrics->recentEvents(['feature' => AIUsageEvent::FEATURE_CHAT]);
        $this->assertCount(1, $rows);
        $this->assertSame(AIUsageEvent::FEATURE_CHAT, $rows->first()->feature);

        $errors = $this->metrics->recentErrors([]);
        $this->assertCount(1, $errors);
        $this->assertSame(AIUsageEvent::STATUS_ERROR, $errors->first()->status);

        Carbon::setTestNow();
    }

    public function test_recent_events_end_date_filter_includes_the_full_selected_day(): void
    {
        $now = Carbon::parse('2026-05-20 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, Carbon::parse('2026-05-18 23:59:59'));
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, Carbon::parse('2026-05-19 00:00:00'));

        $rows = $this->metrics->recentEvents([
            'start_date' => '2026-05-18',
            'end_date' => '2026-05-18',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-05-18 23:59:59', $rows->first()->created_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_user_presence_listing_filters_by_status(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        User::factory()->create(['last_seen_at' => $now->copy()->subSeconds(30)]); // online
        User::factory()->create(['last_seen_at' => $now->copy()->subMinutes(8)]); // idle
        User::factory()->create(['last_seen_at' => $now->copy()->subDay()]); // offline

        $online = $this->metrics->userPresenceListing(['status' => 'online'], 50, $now);
        $idle = $this->metrics->userPresenceListing(['status' => 'idle'], 50, $now);
        $offline = $this->metrics->userPresenceListing(['status' => 'offline'], 50, $now);

        $this->assertCount(1, $online);
        $this->assertSame('online', $online->first()->getAttribute('presence_status'));

        $this->assertCount(1, $idle);
        $this->assertSame('idle', $idle->first()->getAttribute('presence_status'));

        $this->assertCount(1, $offline);
        $this->assertSame('offline', $offline->first()->getAttribute('presence_status'));

        Carbon::setTestNow();
    }

    public function test_document_listing_returns_status_counts(): void
    {
        $user = User::factory()->create();

        Document::create([
            'user_id' => $user->id,
            'filename' => 'a.pdf',
            'original_name' => 'a.pdf',
            'file_path' => 'docs/a.pdf',
            'status' => 'ready',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
        ]);

        Document::create([
            'user_id' => $user->id,
            'filename' => 'b.pdf',
            'original_name' => 'b.pdf',
            'file_path' => 'docs/b.pdf',
            'status' => 'error',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
        ]);

        $payload = $this->metrics->documentListing();

        $this->assertSame(1, $payload['status_counts']['ready'] ?? 0);
        $this->assertSame(1, $payload['status_counts']['error'] ?? 0);
        $this->assertSame(3072, $payload['total_size_bytes']);
        $this->assertSame(2, $payload['rows']->count());
    }
}
