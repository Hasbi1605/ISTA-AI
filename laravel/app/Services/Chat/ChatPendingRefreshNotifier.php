<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;

class ChatPendingRefreshNotifier
{
    private const TTL_SECONDS = 600;

    public function signal(int $userId, int $conversationId, ?int $messageId = null): void
    {
        if ($userId <= 0 || $conversationId <= 0) {
            return;
        }

        Cache::put($this->cacheKey($userId, $conversationId), [
            'messageId' => $messageId,
            'signaledAt' => now()->timestamp,
        ], self::TTL_SECONDS);
    }

    /**
     * @param  array<int, int|string>  $conversationIds
     * @return list<array{conversationId: int, messageId: ?int}>
     */
    public function pullSignals(int $userId, array $conversationIds): array
    {
        if ($userId <= 0 || $conversationIds === []) {
            return [];
        }

        $signals = [];

        foreach ($conversationIds as $conversationId) {
            $conversationId = (int) $conversationId;

            if ($conversationId <= 0) {
                continue;
            }

            $payload = Cache::pull($this->cacheKey($userId, $conversationId));

            if (! is_array($payload)) {
                continue;
            }

            $messageId = isset($payload['messageId']) ? (int) $payload['messageId'] : null;

            $signals[] = [
                'conversationId' => $conversationId,
                'messageId' => $messageId > 0 ? $messageId : null,
            ];
        }

        return $signals;
    }

    private function cacheKey(int $userId, int $conversationId): string
    {
        return "ista:chat:pending-refresh:{$userId}:{$conversationId}";
    }
}
