<?php

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\ChatPendingRefreshNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ChatPendingRefreshNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_signal_and_pull_signals_are_scoped_per_user_and_conversation(): void
    {
        $notifier = app(ChatPendingRefreshNotifier::class);

        $notifier->signal(10, 100, 9001);
        $notifier->signal(10, 101, 9002);
        $notifier->signal(11, 100, 9003);

        $signals = $notifier->pullSignals(10, [100, 101, 999]);

        $this->assertCount(2, $signals);
        $this->assertSame([
            ['conversationId' => 100, 'messageId' => 9001],
            ['conversationId' => 101, 'messageId' => 9002],
        ], $signals);
        $this->assertSame([], $notifier->pullSignals(10, [100, 101]));
        $this->assertSame([
            ['conversationId' => 100, 'messageId' => 9003],
        ], $notifier->pullSignals(11, [100]));
    }

    public function test_pull_signals_ignores_invalid_ids(): void
    {
        $notifier = app(ChatPendingRefreshNotifier::class);

        $this->assertSame([], $notifier->pullSignals(0, [1]));
        $this->assertSame([], $notifier->pullSignals(1, []));
        $this->assertSame([], $notifier->pullSignals(1, [0, -5, 'abc']));
    }

    public function test_signal_is_stored_in_cache_with_ttl(): void
    {
        Cache::flush();

        app(ChatPendingRefreshNotifier::class)->signal(5, 6, 7);

        $this->assertTrue(Cache::has('ista:chat:pending-refresh:5:6'));
    }
}
