<?php

namespace App\Services\Admin;

use App\Models\AIUsageEvent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Memo;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates metrics for the admin monitoring dashboard.
 *
 * The service is intentionally read-only. All queries are scoped by date
 * ranges and do not return prompt or document content. Strings are only
 * pulled from metadata that is already sanitized by AIUsageEventService.
 */
class AdminMetricsService
{
    /**
     * Maximum number of recent rows surfaced through the dashboard tables.
     */
    public const RECENT_ROWS_LIMIT = 100;

    /**
     * Default activity window when admins do not pick one (in days).
     */
    public const DEFAULT_RANGE_DAYS = 7;

    /**
     * Maximum lookback range admins are allowed to pick (in days).
     */
    public const MAX_RANGE_DAYS = 90;

    /**
     * Online presence window (minutes since last_seen_at).
     */
    public const PRESENCE_ONLINE_MINUTES = 2;

    /**
     * Idle presence window (minutes since last_seen_at).
     */
    public const PRESENCE_IDLE_MINUTES = 15;

    /**
     * Compute the headline KPI block for the overview dashboard.
     *
     * @return array<string, int|float|null>
     */
    public function overviewKpis(?CarbonInterface $now = null): array
    {
        $now = $now ? $now->copy() : now();
        $startOfToday = $now->copy()->startOfDay();
        $startOfWeek = $now->copy()->subDays(6)->startOfDay();

        $usersBase = User::query();

        $eventsToday = AIUsageEvent::query()
            ->where('created_at', '>=', $startOfToday);

        $eventsLatencyToday = (clone $eventsToday)
            ->where('action', AIUsageEvent::ACTION_COMPLETED)
            ->where('status', AIUsageEvent::STATUS_SUCCESS)
            ->whereNotNull('latency_ms');

        $conversationsToday = Conversation::query()
            ->where('created_at', '>=', $startOfToday);

        $messagesToday = Message::query()
            ->where('role', 'user')
            ->where('created_at', '>=', $startOfToday);

        $memosToday = Memo::query()
            ->where('created_at', '>=', $startOfToday);

        $memosWeek = Memo::query()
            ->where('created_at', '>=', $startOfWeek);

        return [
            'total_users' => (clone $usersBase)->count(),
            'active_users_today' => $this->countActiveUsersBetween($startOfToday, $now),
            'active_users_week' => $this->countActiveUsersBetween($startOfWeek, $now),
            'online_users' => (clone $usersBase)
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', $now->copy()->subMinutes(self::PRESENCE_ONLINE_MINUTES))
                ->count(),
            'idle_users' => (clone $usersBase)
                ->whereNotNull('last_seen_at')
                ->whereBetween('last_seen_at', [
                    $now->copy()->subMinutes(self::PRESENCE_IDLE_MINUTES),
                    $now->copy()->subMinutes(self::PRESENCE_ONLINE_MINUTES),
                ])
                ->count(),
            'conversations_today' => $conversationsToday->count(),
            'messages_today' => $messagesToday->count(),
            'ai_requests_today' => (clone $eventsToday)->count(),
            'ai_success_today' => (clone $eventsToday)
                ->where('status', AIUsageEvent::STATUS_SUCCESS)
                ->count(),
            'ai_failed_today' => (clone $eventsToday)
                ->where('status', AIUsageEvent::STATUS_ERROR)
                ->count(),
            'ai_pending_today' => (clone $eventsToday)
                ->where('status', AIUsageEvent::STATUS_PENDING)
                ->count(),
            'avg_latency_ms_today' => $this->roundedAverage(
                (clone $eventsLatencyToday)->avg('latency_ms')
            ),
            'documents_ready' => Document::query()->where('status', 'ready')->count(),
            'documents_processing' => Document::query()
                ->whereIn('status', ['pending', 'processing'])
                ->count(),
            'documents_failed' => Document::query()
                ->where('status', 'error')
                ->count(),
            'memos_today' => $memosToday->count(),
            'memos_week' => $memosWeek->count(),
        ];
    }

    /**
     * Build a daily activity series suitable for a small chart on the
     * overview page (events per day for the last $days days).
     *
     * @return array<int, array{date: string, total: int, success: int, failed: int}>
     */
    public function dailyActivitySeries(int $days = self::DEFAULT_RANGE_DAYS, ?CarbonInterface $now = null): array
    {
        $now = $now ? $now->copy() : now();
        $days = max(1, min($days, self::MAX_RANGE_DAYS));
        $start = $now->copy()->subDays($days - 1)->startOfDay();

        $rows = AIUsageEvent::query()
            ->selectRaw('DATE(created_at) as event_date')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success", [AIUsageEvent::STATUS_SUCCESS])
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed", [AIUsageEvent::STATUS_ERROR])
            ->where('created_at', '>=', $start)
            ->groupBy('event_date')
            ->orderBy('event_date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->event_date);

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $row = $rows->get($date);
            $series[] = [
                'date' => $date,
                'total' => (int) ($row->total ?? 0),
                'success' => (int) ($row->success ?? 0),
                'failed' => (int) ($row->failed ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Distribution of events grouped by `feature` for the given date range.
     *
     * @return array<int, array{feature: string, total: int, success: int, failed: int}>
     */
    public function featureDistribution(?CarbonInterface $start = null, ?CarbonInterface $end = null): array
    {
        $end = $end ? $end->copy() : now();
        $start = $start ? $start->copy() : $end->copy()->subDays(self::DEFAULT_RANGE_DAYS - 1)->startOfDay();

        return AIUsageEvent::query()
            ->selectRaw('feature, COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success", [AIUsageEvent::STATUS_SUCCESS])
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed", [AIUsageEvent::STATUS_ERROR])
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('feature')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'feature' => (string) $row->feature,
                'total' => (int) $row->total,
                'success' => (int) $row->success,
                'failed' => (int) $row->failed,
            ])
            ->all();
    }

    /**
     * Recent AI usage events for the activity feed and usage page table.
     *
     * @param  array<string, mixed>  $filters
     */
    public function recentEvents(array $filters = [], int $limit = self::RECENT_ROWS_LIMIT): Collection
    {
        $limit = max(1, min($limit, self::RECENT_ROWS_LIMIT));

        $query = $this->applyEventFilters(AIUsageEvent::query(), $filters)
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->limit($limit);

        return $query->get();
    }

    /**
     * Recent failed/blocked events for the errors page.
     *
     * @param  array<string, mixed>  $filters
     */
    public function recentErrors(array $filters = [], int $limit = self::RECENT_ROWS_LIMIT): Collection
    {
        $limit = max(1, min($limit, self::RECENT_ROWS_LIMIT));

        return $this->applyEventFilters(AIUsageEvent::query(), $filters)
            ->whereIn('status', [AIUsageEvent::STATUS_ERROR, AIUsageEvent::STATUS_BLOCKED])
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * User listing for the /admin/users page including computed presence
     * status and per-user activity counters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function userPresenceListing(array $filters = [], int $limit = self::RECENT_ROWS_LIMIT, ?CarbonInterface $now = null): Collection
    {
        $now = $now ? $now->copy() : now();
        $limit = max(1, min($limit, self::RECENT_ROWS_LIMIT));
        $startOfToday = $now->copy()->startOfDay();
        $startOfWeek = $now->copy()->subDays(6)->startOfDay();

        $query = User::query()
            ->select([
                'id',
                'name',
                'email',
                'role',
                'last_seen_at',
                'last_active_feature',
                'created_at',
            ]);

        if (! empty($filters['role']) && in_array($filters['role'], User::ROLES, true)) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['status'])) {
            $status = $filters['status'];

            if ($status === 'online') {
                $query->whereNotNull('last_seen_at')
                    ->where('last_seen_at', '>=', $now->copy()->subMinutes(self::PRESENCE_ONLINE_MINUTES));
            } elseif ($status === 'idle') {
                $query->whereNotNull('last_seen_at')
                    ->whereBetween('last_seen_at', [
                        $now->copy()->subMinutes(self::PRESENCE_IDLE_MINUTES),
                        $now->copy()->subMinutes(self::PRESENCE_ONLINE_MINUTES),
                    ]);
            } elseif ($status === 'offline') {
                $query->where(function (Builder $builder) use ($now) {
                    $builder->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', $now->copy()->subMinutes(self::PRESENCE_IDLE_MINUTES));
                });
            }
        }

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $builder) use ($term) {
                $builder->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        $users = $query
            ->orderByRaw('CASE WHEN last_seen_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        if ($users->isEmpty()) {
            return $users;
        }

        $ids = $users->pluck('id')->all();

        $eventsToday = AIUsageEvent::query()
            ->whereIn('user_id', $ids)
            ->where('created_at', '>=', $startOfToday)
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $eventsWeek = AIUsageEvent::query()
            ->whereIn('user_id', $ids)
            ->where('created_at', '>=', $startOfWeek)
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $conversationCounts = Conversation::query()
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $documentCounts = Document::query()
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $memoCounts = Memo::query()
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        return $users->map(function (User $user) use ($now, $eventsToday, $eventsWeek, $conversationCounts, $documentCounts, $memoCounts) {
            $user->setAttribute('presence_status', $this->presenceStatus($user, $now));
            $user->setAttribute('events_today', (int) ($eventsToday[$user->id] ?? 0));
            $user->setAttribute('events_week', (int) ($eventsWeek[$user->id] ?? 0));
            $user->setAttribute('conversation_count', (int) ($conversationCounts[$user->id] ?? 0));
            $user->setAttribute('document_count', (int) ($documentCounts[$user->id] ?? 0));
            $user->setAttribute('memo_count', (int) ($memoCounts[$user->id] ?? 0));

            return $user;
        });
    }

    /**
     * Document listing for the /admin/documents page with status counters.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection, status_counts: array<string, int>, mime_counts: array<string, int>, total_size_bytes: int}
     */
    public function documentListing(array $filters = [], int $limit = self::RECENT_ROWS_LIMIT): array
    {
        $limit = max(1, min($limit, self::RECENT_ROWS_LIMIT));

        $query = Document::query()
            ->select([
                'id',
                'user_id',
                'original_name',
                'mime_type',
                'file_size_bytes',
                'status',
                'preview_status',
                'created_at',
                'updated_at',
            ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['search'])) {
            $query->where('original_name', 'like', '%'.trim((string) $filters['search']).'%');
        }

        $rows = $query->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $statusCounts = Document::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->mapWithKeys(fn ($total, $status) => [(string) ($status ?: 'unknown') => (int) $total])
            ->all();

        $mimeCounts = Document::query()
            ->selectRaw('mime_type, COUNT(*) as total')
            ->groupBy('mime_type')
            ->pluck('total', 'mime_type')
            ->mapWithKeys(fn ($total, $mime) => [(string) ($mime ?: 'unknown') => (int) $total])
            ->all();

        $totalSize = (int) Document::query()->sum('file_size_bytes');

        return [
            'rows' => $rows,
            'status_counts' => $statusCounts,
            'mime_counts' => $mimeCounts,
            'total_size_bytes' => $totalSize,
        ];
    }

    /**
     * Apply the standard event filter set to a query builder.
     *
     * @param  array<string, mixed>  $filters
     */
    public function applyEventFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['feature'])) {
            $query->where('feature', $filters['feature']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['request_id'])) {
            $query->where('request_id', $filters['request_id']);
        }

        $start = $this->parseDate($filters['start_date'] ?? null);
        $end = $this->parseDate($filters['end_date'] ?? null);

        if ($start !== null && $end !== null && $start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $maxStart = now()->subDays(self::MAX_RANGE_DAYS - 1)->startOfDay();

        if ($start === null) {
            $start = now()->subDays(self::DEFAULT_RANGE_DAYS - 1)->startOfDay();
        } elseif ($start->lessThan($maxStart)) {
            $start = $maxStart;
        }

        if ($end === null) {
            $end = now();
        }

        $query->whereBetween('created_at', [$start, $end]);

        return $query;
    }

    /**
     * Resolve the presence status string for a single user instance.
     */
    public function presenceStatus(User $user, ?CarbonInterface $now = null): string
    {
        $now = $now ? $now->copy() : now();
        $lastSeen = $user->last_seen_at;

        if ($lastSeen === null) {
            return 'offline';
        }

        $diff = $lastSeen->diffInMinutes($now, false);

        if ($diff < 0) {
            return 'online';
        }

        if ($diff <= self::PRESENCE_ONLINE_MINUTES) {
            return 'online';
        }

        if ($diff <= self::PRESENCE_IDLE_MINUTES) {
            return 'idle';
        }

        return 'offline';
    }

    /**
     * Parse a user-supplied date string safely. Returns null when the
     * input is empty or malformed.
     */
    public function safeParseDate(mixed $value): ?Carbon
    {
        return $this->parseDate($value);
    }

    private function countActiveUsersBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        return User::query()
            ->where(function (Builder $builder) use ($start, $end) {
                $builder->whereBetween('last_seen_at', [$start, $end])
                    ->orWhereExists(function ($query) use ($start, $end) {
                        $query->select(DB::raw(1))
                            ->from('ai_usage_events')
                            ->whereColumn('ai_usage_events.user_id', 'users.id')
                            ->whereBetween('ai_usage_events.created_at', [$start, $end]);
                    });
            })
            ->count();
    }

    private function roundedAverage(?float $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) round($value);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
