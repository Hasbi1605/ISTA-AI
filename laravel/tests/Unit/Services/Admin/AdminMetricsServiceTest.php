<?php

namespace Tests\Unit\Services\Admin;

use App\Models\AIUsageEvent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
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

    public function test_overview_comparisons_use_real_previous_periods(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();

        // Today: 4 requests, 3 successes, 1 failure, avg latency 2s.
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHours(3), 1000);
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHours(2), 2000);
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_DOCUMENT_RAG, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHour(), 3000);
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subMinutes(30));

        // Yesterday: 2 requests, 1 success, 1 failure, avg latency 4s.
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subDay()->setTime(10, 0), 4000);
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subDay()->setTime(11, 0));

        // Previous 7-day window: 8 requests, 4 failures.
        foreach ([8, 9, 10, 11] as $daysAgo) {
            $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subDays($daysAgo));
            $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subDays($daysAgo), 5000);
        }

        $comparisons = $this->metrics->overviewComparisons($now);

        $this->assertTrue($comparisons['ai_requests']['has_comparison']);
        $this->assertSame(4, $comparisons['ai_requests']['current']);
        $this->assertSame(2, $comparisons['ai_requests']['previous']);
        $this->assertSame('up', $comparisons['ai_requests']['direction']);
        $this->assertSame('success', $comparisons['ai_requests']['tone']);
        $this->assertEqualsWithDelta(100.0, $comparisons['ai_requests']['delta_percent'], 0.001);

        $this->assertEqualsWithDelta(75.0, $comparisons['success_rate']['current'], 0.001);
        $this->assertEqualsWithDelta(50.0, $comparisons['success_rate']['previous'], 0.001);
        $this->assertEqualsWithDelta(25.0, $comparisons['success_rate']['delta'], 0.001);
        $this->assertSame('success', $comparisons['success_rate']['tone']);

        $this->assertSame(2000, $comparisons['avg_latency_ms']['current']);
        $this->assertSame(4000, $comparisons['avg_latency_ms']['previous']);
        $this->assertSame('down', $comparisons['avg_latency_ms']['direction']);
        $this->assertSame('success', $comparisons['avg_latency_ms']['tone']);

        $this->assertEqualsWithDelta(25.0, $comparisons['error_rate']['current'], 0.001);
        $this->assertEqualsWithDelta(50.0, $comparisons['error_rate']['previous'], 0.001);
        $this->assertSame('down', $comparisons['error_rate']['direction']);
        $this->assertSame('success', $comparisons['error_rate']['tone']);

        $this->assertSame(2, $comparisons['errors_7d']['current']);
        $this->assertSame(4, $comparisons['errors_7d']['previous']);
        $this->assertSame('down', $comparisons['errors_7d']['direction']);
        $this->assertEqualsWithDelta(-50.0, $comparisons['errors_7d']['delta_percent'], 0.001);

        $this->assertEqualsWithDelta(33.333, $comparisons['error_rate_7d']['current'], 0.01);
        $this->assertEqualsWithDelta(50.0, $comparisons['error_rate_7d']['previous'], 0.001);
        $this->assertSame('success', $comparisons['error_rate_7d']['tone']);

        Carbon::setTestNow();
    }

    public function test_overview_comparisons_do_not_fake_trends_without_previous_data(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHour(), 1000);

        $comparisons = $this->metrics->overviewComparisons($now);

        $this->assertFalse($comparisons['ai_requests']['has_comparison']);
        $this->assertSame('none', $comparisons['ai_requests']['direction']);
        $this->assertNull($comparisons['ai_requests']['delta_percent']);
        $this->assertFalse($comparisons['avg_latency_ms']['has_comparison']);
        $this->assertFalse($comparisons['errors_7d']['has_comparison']);

        Carbon::setTestNow();
    }

    public function test_last_overview_activity_uses_operational_data_not_dashboard_render_time(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create(['last_seen_at' => $now]);
        $this->assertNull($this->metrics->lastOverviewActivityAt());

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subMinutes(20));

        $this->assertSame(
            $now->copy()->subMinutes(20)->toDateTimeString(),
            $this->metrics->lastOverviewActivityAt()?->toDateTimeString(),
        );

        Carbon::setTestNow();
    }

    private function makeEvent(int $userId, string $feature, string $action, string $status, Carbon $createdAt, ?int $latencyMs = null, ?string $errorCode = null, ?array $metadata = null): AIUsageEvent
    {
        $event = new AIUsageEvent([
            'user_id' => $userId,
            'feature' => $feature,
            'action' => $action,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'error_code' => $errorCode,
            'metadata' => $metadata,
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

    public function test_user_presence_summary_returns_presence_counts(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        User::factory()->create(['last_seen_at' => $now->copy()->subSeconds(30)]);
        User::factory()->create(['last_seen_at' => $now->copy()->subMinutes(8)]);
        User::factory()->create(['last_seen_at' => $now->copy()->subDay()]);
        User::factory()->create(['last_seen_at' => null]);

        $summary = $this->metrics->userPresenceSummary($now);

        $this->assertSame([
            'total' => 4,
            'online' => 1,
            'idle' => 1,
            'offline' => 2,
        ], $summary);

        Carbon::setTestNow();
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

    public function test_error_event_summary_counts_filtered_failed_and_blocked_events(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subMinutes(30), null, 'rate_limited');
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_BLOCKED, $now->copy()->subMinutes(20));
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_DOCUMENT_RAG, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subMinutes(10));

        $summary = $this->metrics->errorEventSummary([
            'feature' => AIUsageEvent::FEATURE_CHAT,
        ]);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['error']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(2, $summary['unique_codes']);
        $this->assertSame($now->copy()->subMinutes(20)->toDateTimeString(), $summary['latest_at']?->toDateTimeString());
        $this->assertSame(AIUsageEvent::FEATURE_CHAT, $summary['by_feature'][0]['feature']);
        $this->assertSame(2, $summary['by_feature'][0]['total']);
        $this->assertContains('rate_limited', collect($summary['by_code'])->pluck('code')->all());
        $this->assertContains('unknown_error', collect($summary['by_code'])->pluck('code')->all());

        Carbon::setTestNow();
    }

    public function test_error_events_listing_paginates_failed_and_blocked_events(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();

        for ($index = 1; $index <= 6; $index++) {
            $this->makeEvent(
                $user->id,
                AIUsageEvent::FEATURE_CHAT,
                AIUsageEvent::ACTION_FAILED,
                AIUsageEvent::STATUS_ERROR,
                $now->copy()->subMinutes($index),
            );
        }

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now);

        $firstPage = $this->metrics->errorEventsListing([], 5, 1);
        $secondPage = $this->metrics->errorEventsListing([], 5, 2);

        $this->assertSame(6, $firstPage->total());
        $this->assertSame(5, $firstPage->count());
        $this->assertTrue($firstPage->hasPages());
        $this->assertSame(1, $secondPage->count());
        $this->assertSame(AIUsageEvent::STATUS_ERROR, $firstPage->first()->status);

        Carbon::setTestNow();
    }

    public function test_error_severity_and_guidance_are_derived_from_error_code(): void
    {
        $user = User::factory()->create();

        $event = $this->makeEvent(
            $user->id,
            AIUsageEvent::FEATURE_CHAT,
            AIUsageEvent::ACTION_FAILED,
            AIUsageEvent::STATUS_ERROR,
            Carbon::parse('2026-05-18 12:00:00'),
            null,
            'error_sentinel',
            [
                'model_label' => 'GPT-4o (Primary)',
                'model_name' => 'openai/gpt-4o',
            ],
        );

        $severity = $this->metrics->errorSeverity($event);
        $guidance = $this->metrics->errorHandlingGuidance($event);
        $detail = $this->metrics->errorEventDetail($event->id);

        $this->assertSame('high', $severity['level']);
        $this->assertSame('High', $severity['label']);
        $this->assertStringContainsString('sentinel', $guidance['summary']);
        $this->assertNotEmpty($guidance['steps']);
        $this->assertSame('High', $detail?->getAttribute('severity_label'));
        $this->assertSame('GPT-4o (Primary)', $detail?->metadata['model_label'] ?? null);
    }

    public function test_usage_event_summary_counts_filtered_statuses(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();

        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subHours(1));
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_STARTED, AIUsageEvent::STATUS_PENDING, $now->copy()->subMinutes(50));
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_ERROR, $now->copy()->subMinutes(40));
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_CHAT, AIUsageEvent::ACTION_FAILED, AIUsageEvent::STATUS_BLOCKED, $now->copy()->subMinutes(30));
        $this->makeEvent($user->id, AIUsageEvent::FEATURE_DOCUMENT_RAG, AIUsageEvent::ACTION_COMPLETED, AIUsageEvent::STATUS_SUCCESS, $now->copy()->subMinutes(20));

        $summary = $this->metrics->usageEventSummary([
            'feature' => AIUsageEvent::FEATURE_CHAT,
        ]);

        $this->assertSame([
            'total' => 4,
            'success' => 1,
            'pending' => 1,
            'failed' => 2,
        ], $summary);

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

    public function test_usage_events_listing_paginates_recent_events(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        $user = User::factory()->create();

        for ($index = 1; $index <= 6; $index++) {
            $this->makeEvent(
                $user->id,
                AIUsageEvent::FEATURE_CHAT,
                AIUsageEvent::ACTION_COMPLETED,
                AIUsageEvent::STATUS_SUCCESS,
                $now->copy()->subMinutes($index),
            );
        }

        $firstPage = $this->metrics->usageEventsListing([], 5, 1);
        $secondPage = $this->metrics->usageEventsListing([], 5, 2);

        $this->assertSame(6, $firstPage->total());
        $this->assertSame(5, $firstPage->count());
        $this->assertTrue($firstPage->hasPages());
        $this->assertSame(1, $secondPage->count());
        $this->assertSame(
            $now->copy()->subMinute()->toDateTimeString(),
            $firstPage->first()->created_at->toDateTimeString(),
        );

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

    public function test_user_presence_listing_paginates_user_rows(): void
    {
        $now = Carbon::parse('2026-05-18 12:00:00');
        Carbon::setTestNow($now);

        for ($index = 1; $index <= 16; $index++) {
            User::factory()->create([
                'name' => sprintf('Paginated User %02d', $index),
                'last_seen_at' => $now->copy()->subMinutes($index + 20),
            ]);
        }

        $firstPage = $this->metrics->userPresenceListing([], 15, $now, 1);
        $secondPage = $this->metrics->userPresenceListing([], 15, $now, 2);

        $this->assertSame(16, $firstPage->total());
        $this->assertSame(15, $firstPage->count());
        $this->assertTrue($firstPage->hasPages());
        $this->assertSame(1, $secondPage->count());

        Carbon::setTestNow();
    }

    public function test_document_listing_returns_status_counts(): void
    {
        $user = User::factory()->create();

        $readyDocument = Document::create([
            'user_id' => $user->id,
            'filename' => 'a.pdf',
            'original_name' => 'a.pdf',
            'file_path' => 'docs/a.pdf',
            'status' => 'ready',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
        ]);

        DocumentChunk::create([
            'document_id' => $readyDocument->id,
            'page_number' => 1,
            'text_content' => 'Internal chunk text.',
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

        $readyPayload = $this->metrics->documentListing([
            'status' => 'ready',
            'type' => 'pdf',
            'user_id' => $user->id,
        ]);

        $this->assertSame(1, $readyPayload['rows']->count());
        $this->assertSame(1, $readyPayload['rows']->getCollection()->first()->chunks_count);
    }
}
