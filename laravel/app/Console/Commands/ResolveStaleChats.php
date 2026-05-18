<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatOrchestrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResolveStaleChats extends Command
{
    /**
     * Signature with configurable timeout in minutes.
     * Default 10 minutes — safely above GenerateChatResponse::$timeout (3 min)
     * to avoid false positives on slow but still-running jobs.
     */
    protected $signature = 'chat:resolve-stale-responses
                            {--minutes=10 : Mark conversations as failed if no AI response after N minutes}';

    protected $description = 'Write an error message for conversations that have been waiting for an AI response too long (dead queue job detector).';

    public function handle(ChatOrchestrationService $orchestrator): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        // Find conversations whose latest message is a user message older than
        // the cutoff, with no assistant message after it.
        // We use a subquery to avoid loading all messages into memory.
        // Use get(['id', 'user_id']) instead of pluck('id', 'user_id') to avoid
        // key collision when one user has multiple stale conversations.
        $staleConversations = Conversation::query()
            ->whereExists(function ($query) use ($cutoff) {
                $query->select(DB::raw(1))
                    ->from('messages as m')
                    ->whereColumn('m.conversation_id', 'conversations.id')
                    ->where('m.role', 'user')
                    ->where('m.created_at', '<=', $cutoff)
                    ->whereNotExists(function ($inner) {
                        $inner->select(DB::raw(1))
                            ->from('messages as m3')
                            ->whereColumn('m3.conversation_id', 'm.conversation_id')
                            ->where(function ($newer) {
                                $newer->whereColumn('m3.created_at', '>', 'm.created_at')
                                    ->orWhere(function ($sameTimestamp) {
                                        $sameTimestamp->whereColumn('m3.created_at', '=', 'm.created_at')
                                            ->whereColumn('m3.id', '>', 'm.id');
                                    });
                            });
                    })
                    ->whereNotExists(function ($inner) {
                        $inner->select(DB::raw(1))
                            ->from('messages as m2')
                            ->whereColumn('m2.conversation_id', 'm.conversation_id')
                            ->where('m2.role', 'assistant')
                            ->whereColumn('m2.created_at', '>', 'm.created_at');
                    });
            })
            ->get(['id', 'user_id']);

        if ($staleConversations->isEmpty()) {
            $this->info('No stale chat responses found.');

            return self::SUCCESS;
        }

        $resolved = 0;
        $skippedActiveStreams = 0;

        foreach ($staleConversations as $conversation) {
            $conversationId = (int) $conversation->id;
            $userId = (int) $conversation->user_id;
            try {
                if ($orchestrator->hasActiveStreamClaim($conversationId)) {
                    $skippedActiveStreams++;

                    continue;
                }

                $result = $orchestrator->saveErrorMessage(
                    (int) $conversationId,
                    'Maaf, respon AI tidak diterima dalam batas waktu yang ditentukan. Silakan coba kirim ulang pesan Anda.',
                    (int) $userId,
                );

                if ($result !== null) {
                    // Touch conversation so Livewire polling detects the change
                    Conversation::query()
                        ->whereKey($conversationId)
                        ->where('user_id', $userId)
                        ->touch();

                    $resolved++;
                }
            } catch (\Throwable $e) {
                Log::warning('ResolveStaleChats: failed to write error message', [
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Resolved {$resolved} stale chat response(s) older than {$minutes} minute(s).");
        if ($skippedActiveStreams > 0) {
            $this->info("Skipped {$skippedActiveStreams} stale chat response(s) with active stream claim.");
        }

        Log::info('ResolveStaleChats completed', [
            'resolved' => $resolved,
            'skipped_active_streams' => $skippedActiveStreams,
            'minutes_threshold' => $minutes,
        ]);

        return self::SUCCESS;
    }
}
