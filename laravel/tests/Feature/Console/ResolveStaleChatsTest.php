<?php

namespace Tests\Feature\Console;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatOrchestrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveStaleChatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_writes_error_message_for_stale_conversation(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Stale conversation',
        ]);

        // User message older than 10 minutes, no assistant reply
        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pesan yang tidak pernah dijawab',
        ]);
        Message::withoutTimestamps(fn () => $userMessage->forceFill([
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ])->save());

        $this->artisan('chat:resolve-stale-responses', ['--minutes' => 10])
            ->expectsOutput('Resolved 1 stale chat response(s) older than 10 minute(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'is_error' => true,
        ]);
    }

    public function test_command_skips_conversation_with_assistant_reply(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Answered conversation',
        ]);

        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pesan yang sudah dijawab',
        ]);
        Message::withoutTimestamps(fn () => $userMessage->forceFill([
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ])->save());

        // Assistant already replied
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Jawaban AI',
        ]);

        $this->artisan('chat:resolve-stale-responses', ['--minutes' => 10])
            ->expectsOutput('No stale chat responses found.')
            ->assertExitCode(0);

        // No additional error message should be written
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'is_error' => true,
        ]);
    }

    public function test_command_skips_recent_unanswered_conversation(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Recent conversation',
        ]);

        // User message only 2 minutes old — within threshold
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pesan baru yang masih diproses',
        ]);

        $this->artisan('chat:resolve-stale-responses', ['--minutes' => 10])
            ->expectsOutput('No stale chat responses found.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'is_error' => true,
        ]);
    }

    public function test_command_skips_stale_conversation_with_active_stream_claim(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Active stream stale conversation',
        ]);

        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pesan stale tetapi stream masih aktif',
        ]);
        Message::withoutTimestamps(fn () => $userMessage->forceFill([
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ])->save());

        $orchestrator = app(ChatOrchestrationService::class);
        $orchestrator->createStreamIntent((int) $conversation->id);

        $this->assertTrue($orchestrator->hasActiveStreamClaim((int) $conversation->id));

        $this->artisan('chat:resolve-stale-responses', ['--minutes' => 10])
            ->expectsOutput('Resolved 0 stale chat response(s) older than 10 minute(s).')
            ->expectsOutput('Skipped 1 stale chat response(s) with active stream claim.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'is_error' => true,
        ]);
    }

    public function test_command_skips_conversation_when_latest_user_message_is_not_stale(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Recent latest user message',
        ]);

        $oldUserMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pesan lama yang belum dijawab',
        ]);
        Message::withoutTimestamps(fn () => $oldUserMessage->forceFill([
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ])->save());

        $recentUserMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pesan baru yang masih diproses',
        ]);
        Message::withoutTimestamps(fn () => $recentUserMessage->forceFill([
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ])->save());

        $this->artisan('chat:resolve-stale-responses', ['--minutes' => 10])
            ->expectsOutput('No stale chat responses found.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'is_error' => true,
        ]);
    }

    public function test_command_is_idempotent(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Idempotent test',
        ]);

        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pesan stale',
        ]);
        Message::withoutTimestamps(fn () => $userMessage->forceFill([
            'created_at' => now()->subMinutes(11),
            'updated_at' => now()->subMinutes(11),
        ])->save());

        // Run twice
        $this->artisan('chat:resolve-stale-responses', ['--minutes' => 10])->assertExitCode(0);
        $this->artisan('chat:resolve-stale-responses', ['--minutes' => 10])->assertExitCode(0);

        // Should only have written ONE error message (second run skips because
        // assistant message already exists)
        $this->assertSame(
            1,
            Message::where('conversation_id', $conversation->id)
                ->where('role', 'assistant')
                ->where('is_error', true)
                ->count()
        );
    }

    public function test_command_resolves_multiple_stale_conversations_for_same_user(): void
    {
        $user = User::factory()->create();

        // Two stale conversations for the same user
        $conv1 = Conversation::create(['user_id' => $user->id, 'title' => 'Stale conv 1']);
        $conv2 = Conversation::create(['user_id' => $user->id, 'title' => 'Stale conv 2']);

        foreach ([$conv1, $conv2] as $conv) {
            $msg = Message::create([
                'conversation_id' => $conv->id,
                'role' => 'user',
                'content' => 'Pesan stale',
            ]);
            Message::withoutTimestamps(fn () => $msg->forceFill([
                'created_at' => now()->subMinutes(11),
                'updated_at' => now()->subMinutes(11),
            ])->save());
        }

        $this->artisan('chat:resolve-stale-responses', ['--minutes' => 10])
            ->expectsOutput('Resolved 2 stale chat response(s) older than 10 minute(s).')
            ->assertExitCode(0);

        // Both conversations must have an error message
        foreach ([$conv1, $conv2] as $conv) {
            $this->assertDatabaseHas('messages', [
                'conversation_id' => $conv->id,
                'role' => 'assistant',
                'is_error' => true,
            ]);
        }
    }

    public function test_stale_chat_resolve_is_registered_in_schedule(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('chat:resolve-stale-responses')
            ->assertExitCode(0);
    }
}
