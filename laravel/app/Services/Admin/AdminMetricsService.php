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
use Illuminate\Pagination\LengthAwarePaginator;
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
     * Presence counters for the /admin/users KPI cards.
     *
     * @return array{total: int, online: int, idle: int, offline: int}
     */
    public function userPresenceSummary(?CarbonInterface $now = null, ?string $role = null): array
    {
        $now = $now ? $now->copy() : now();
        $baseQuery = User::query();

        if ($role !== null && in_array($role, User::ROLES, true)) {
            $baseQuery->where('role', $role);
        }

        $total = (clone $baseQuery)->count();

        $online = (clone $baseQuery)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $now->copy()->subMinutes(self::PRESENCE_ONLINE_MINUTES))
            ->count();

        $idle = (clone $baseQuery)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $now->copy()->subMinutes(self::PRESENCE_ONLINE_MINUTES))
            ->where('last_seen_at', '>=', $now->copy()->subMinutes(self::PRESENCE_IDLE_MINUTES))
            ->count();

        return [
            'total' => $total,
            'online' => $online,
            'idle' => $idle,
            'offline' => max(0, $total - $online - $idle),
        ];
    }

    /**
     * Compare headline overview metrics against their previous period.
     *
     * @return array<string, array<string, float|int|string|bool|null>>
     */
    public function overviewComparisons(?CarbonInterface $now = null): array
    {
        $now = $now ? $now->copy() : now();
        $todayStart = $now->copy()->startOfDay();
        $yesterdayStart = $now->copy()->subDay()->startOfDay();
        $yesterdayEnd = $now->copy()->subDay()->endOfDay();

        $currentDayCounts = $this->eventStatusCountsBetween($todayStart, $now);
        $previousDayCounts = $this->eventStatusCountsBetween($yesterdayStart, $yesterdayEnd);

        $currentDayLatency = $this->averageLatencyBetween($todayStart, $now);
        $previousDayLatency = $this->averageLatencyBetween($yesterdayStart, $yesterdayEnd);

        $currentSevenStart = $now->copy()->subDays(6)->startOfDay();
        $previousSevenStart = $now->copy()->subDays(13)->startOfDay();
        $previousSevenEnd = $now->copy()->subDays(7)->endOfDay();

        $currentSevenCounts = $this->eventStatusCountsBetween($currentSevenStart, $now);
        $previousSevenCounts = $this->eventStatusCountsBetween($previousSevenStart, $previousSevenEnd);

        return [
            'ai_requests' => $this->comparisonPayload(
                $currentDayCounts['total'],
                $previousDayCounts['total'],
                $previousDayCounts['total'] > 0,
                'percent',
                true,
            ),
            'success_rate' => $this->comparisonPayload(
                $this->rate($currentDayCounts['success'], $currentDayCounts['total']),
                $this->rate($previousDayCounts['success'], $previousDayCounts['total']),
                $previousDayCounts['total'] > 0,
                'percentage_points',
                true,
            ),
            'avg_latency_ms' => $this->comparisonPayload(
                $currentDayLatency,
                $previousDayLatency,
                $currentDayLatency !== null && $previousDayLatency !== null,
                'milliseconds',
                false,
            ),
            'error_rate' => $this->comparisonPayload(
                $this->rate($currentDayCounts['failed'], $currentDayCounts['total']),
                $this->rate($previousDayCounts['failed'], $previousDayCounts['total']),
                $previousDayCounts['total'] > 0,
                'percentage_points',
                false,
            ),
            'errors_7d' => $this->comparisonPayload(
                $currentSevenCounts['failed'],
                $previousSevenCounts['failed'],
                $previousSevenCounts['failed'] > 0,
                'percent',
                false,
            ),
            'error_rate_7d' => $this->comparisonPayload(
                $this->rate($currentSevenCounts['failed'], $currentSevenCounts['total']),
                $this->rate($previousSevenCounts['failed'], $previousSevenCounts['total']),
                $previousSevenCounts['total'] > 0,
                'percentage_points',
                false,
            ),
        ];
    }

    /**
     * Latest timestamp among operational data that can change overview metrics.
     */
    public function lastOverviewActivityAt(): ?Carbon
    {
        return collect([
            AIUsageEvent::query()->max('created_at'),
            Conversation::query()->max('updated_at'),
            Document::query()->max('updated_at'),
            Memo::query()->max('updated_at'),
            Message::query()->max('created_at'),
        ])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sortDesc()
            ->first();
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
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success', [AIUsageEvent::STATUS_SUCCESS])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [AIUsageEvent::STATUS_ERROR])
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
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success', [AIUsageEvent::STATUS_SUCCESS])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [AIUsageEvent::STATUS_ERROR])
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
     * Paginated AI usage events for the dedicated /admin/usage table.
     *
     * @param  array<string, mixed>  $filters
     */
    public function usageEventsListing(array $filters = [], int $perPage = 5, ?int $page = null): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::RECENT_ROWS_LIMIT));

        return $this->applyEventFilters(AIUsageEvent::query(), $filters)
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Aggregate usage KPI counters for the /admin/usage page.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total: int, success: int, pending: int, failed: int}
     */
    public function usageEventSummary(array $filters = []): array
    {
        $row = $this->applyEventFilters(AIUsageEvent::query(), $filters)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success', [AIUsageEvent::STATUS_SUCCESS])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending', [AIUsageEvent::STATUS_PENDING])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as failed', [
                AIUsageEvent::STATUS_ERROR,
                AIUsageEvent::STATUS_BLOCKED,
            ])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'success' => (int) ($row->success ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'failed' => (int) ($row->failed ?? 0),
        ];
    }

    /**
     * Recent failed/blocked events for the errors page.
     *
     * @param  array<string, mixed>  $filters
     */
    public function recentErrors(array $filters = [], int $limit = self::RECENT_ROWS_LIMIT): Collection
    {
        $limit = max(1, min($limit, self::RECENT_ROWS_LIMIT));

        return $this->errorEventsQuery($filters)
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AIUsageEvent $event) => $this->attachErrorAttributes($event));
    }

    /**
     * Paginated failed/blocked events for the dedicated /admin/errors table.
     *
     * @param  array<string, mixed>  $filters
     */
    public function errorEventsListing(array $filters = [], int $perPage = 5, ?int $page = null): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::RECENT_ROWS_LIMIT));

        $errors = $this->errorEventsQuery($filters)
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $errors->setCollection(
            $errors->getCollection()->map(fn (AIUsageEvent $event) => $this->attachErrorAttributes($event))
        );

        return $errors;
    }

    /**
     * Aggregate failed/blocked event counters for the /admin/errors page.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total: int, error: int, blocked: int, unique_codes: int, latest_at: Carbon|null, by_feature: array<int, array{feature: string, total: int}>, by_code: array<int, array{code: string, total: int}>}
     */
    public function errorEventSummary(array $filters = []): array
    {
        $baseQuery = $this->errorEventsQuery($filters);

        $row = (clone $baseQuery)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as error', [AIUsageEvent::STATUS_ERROR])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as blocked', [AIUsageEvent::STATUS_BLOCKED])
            ->selectRaw("COUNT(DISTINCT COALESCE(error_code, 'unknown_error')) as unique_codes")
            ->first();

        $latestAt = (clone $baseQuery)->max('created_at');

        $byFeature = (clone $baseQuery)
            ->selectRaw('feature, COUNT(*) as total')
            ->groupBy('feature')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'feature' => (string) $row->feature,
                'total' => (int) $row->total,
            ])
            ->all();

        $byCode = (clone $baseQuery)
            ->selectRaw("COALESCE(error_code, 'unknown_error') as code, COUNT(*) as total")
            ->groupByRaw("COALESCE(error_code, 'unknown_error')")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'code' => (string) $row->code,
                'total' => (int) $row->total,
            ])
            ->all();

        return [
            'total' => (int) ($row->total ?? 0),
            'error' => (int) ($row->error ?? 0),
            'blocked' => (int) ($row->blocked ?? 0),
            'unique_codes' => (int) ($row->unique_codes ?? 0),
            'latest_at' => $latestAt ? Carbon::parse($latestAt) : null,
            'by_feature' => $byFeature,
            'by_code' => $byCode,
        ];
    }

    public function errorEventDetail(int $eventId): ?AIUsageEvent
    {
        $event = AIUsageEvent::query()
            ->whereKey($eventId)
            ->whereIn('status', [AIUsageEvent::STATUS_ERROR, AIUsageEvent::STATUS_BLOCKED])
            ->with('user:id,name,email,role')
            ->first();

        return $event instanceof AIUsageEvent ? $this->attachErrorAttributes($event) : null;
    }

    /**
     * @return array{level: string, label: string, tone: string, description: string}
     */
    public function errorSeverity(AIUsageEvent $event): array
    {
        $code = strtolower((string) ($event->error_code ?: 'unknown_error'));
        $reason = strtolower((string) data_get($event->metadata, 'reason', ''));

        $criticalCodes = ['job_failed', 'ai_unavailable', 'service_unavailable', 'all_models_failed'];
        $highCodes = ['error_sentinel', 'stream_exception', 'document_context_unavailable', 'ingest_failed'];
        $mediumCodes = ['drive_download_failed', 'drive_temp_unavailable', 'storage_failed', 'persist_failed', 'draft_request_failed', 'unsupported_provider'];
        $lowCodes = ['invalid_mime_type', 'file_too_large', 'validation_failed', 'format_requires_table', 'already_imported'];

        if (in_array($code, $criticalCodes, true) || str_contains($reason, 'all_models')) {
            return [
                'level' => 'critical',
                'label' => 'Critical',
                'tone' => 'critical',
                'description' => 'Kemungkinan gangguan sistem atau pipeline AI utama.',
            ];
        }

        if (in_array($code, $highCodes, true)) {
            return [
                'level' => 'high',
                'label' => 'High',
                'tone' => 'danger',
                'description' => 'Request user gagal dan perlu pengecekan operasional.',
            ];
        }

        if ($event->status === AIUsageEvent::STATUS_BLOCKED || in_array($code, $mediumCodes, true)) {
            return [
                'level' => 'medium',
                'label' => 'Medium',
                'tone' => 'warning',
                'description' => 'Masalah integrasi, policy, atau proses yang perlu dipantau.',
            ];
        }

        if (in_array($code, $lowCodes, true)) {
            return [
                'level' => 'low',
                'label' => 'Low',
                'tone' => 'neutral',
                'description' => 'Masalah validasi atau input yang biasanya bisa diperbaiki user.',
            ];
        }

        return [
            'level' => 'medium',
            'label' => 'Medium',
            'tone' => 'warning',
            'description' => 'Belum ada klasifikasi khusus untuk kode error ini.',
        ];
    }

    /**
     * @return array{summary: string, causes: array<int, string>, steps: array<int, string>}
     */
    public function errorHandlingGuidance(AIUsageEvent $event): array
    {
        $code = strtolower((string) ($event->error_code ?: 'unknown_error'));

        return match ($code) {
            'error_sentinel' => [
                'summary' => 'Python AI service mengembalikan sentinel error, biasanya setelah provider/model tidak berhasil memberi respons.',
                'causes' => [
                    'Provider AI terkena rate limit atau timeout.',
                    'Token provider tidak valid atau sudah melewati kuota.',
                    'Semua fallback model gagal sebelum menghasilkan jawaban.',
                ],
                'steps' => [
                    'Cari request ID di log Laravel dan Python AI.',
                    'Cek health service Python AI dan koneksi provider.',
                    'Periksa token, rate limit, dan urutan fallback model.',
                    'Minta user ulangi request setelah service/model pulih.',
                ],
            ],
            'stream_exception' => [
                'summary' => 'Streaming jawaban terputus karena exception saat Laravel membaca respons AI.',
                'causes' => [
                    'Koneksi SSE terputus atau timeout.',
                    'Python AI service menutup stream secara tidak normal.',
                    'Ada exception saat parsing metadata stream.',
                ],
                'steps' => [
                    'Cek log request ID pada Laravel.',
                    'Bandingkan waktu error dengan log Python AI.',
                    'Uji ulang chat singkat untuk memastikan stream pulih.',
                ],
            ],
            'document_context_unavailable' => [
                'summary' => 'Request membutuhkan konteks dokumen, tetapi dokumen belum siap atau tidak tersedia untuk retrieval.',
                'causes' => [
                    'Dokumen masih pending/processing.',
                    'Indexing atau Chroma belum memuat dokumen.',
                    'Dokumen dihapus, bukan milik user, atau gagal diproses.',
                ],
                'steps' => [
                    'Cek status dokumen pada tab Documents.',
                    'Pastikan dokumen berstatus ready dan punya hasil indexing.',
                    'Ulang proses dokumen bila status error atau indexing kosong.',
                ],
            ],
            'job_failed' => [
                'summary' => 'Background job chat gagal setelah retry queue.',
                'causes' => [
                    'Worker queue berhenti atau timeout.',
                    'Dependency AI/Python sedang tidak stabil.',
                    'Exception tidak tertangani pada pipeline job fallback.',
                ],
                'steps' => [
                    'Cek worker queue dan failed jobs.',
                    'Cari stack trace dengan request ID atau conversation ID.',
                    'Restart worker setelah akar masalah diperbaiki.',
                ],
            ],
            'ingest_failed' => [
                'summary' => 'Proses ingest dokumen gagal sehingga dokumen tidak siap dipakai RAG.',
                'causes' => [
                    'File tidak bisa diekstrak atau format bermasalah.',
                    'Embedding provider gagal atau rate limited.',
                    'Storage/vector index tidak tersedia.',
                ],
                'steps' => [
                    'Cek detail dokumen dan log proses ingest.',
                    'Pastikan embedding provider dan Chroma tersedia.',
                    'Minta user upload ulang bila file rusak.',
                ],
            ],
            'format_requires_table' => [
                'summary' => 'User meminta export spreadsheet, tetapi jawaban AI tidak memiliki tabel.',
                'causes' => [
                    'Konten jawaban berupa paragraf biasa.',
                    'Format export tidak cocok dengan struktur jawaban.',
                ],
                'steps' => [
                    'Minta user menghasilkan jawaban dalam bentuk tabel.',
                    'Gunakan format dokumen non-spreadsheet untuk konten naratif.',
                ],
            ],
            'invalid_mime_type', 'file_too_large', 'validation_failed' => [
                'summary' => 'Request ditolak karena validasi input atau batasan file.',
                'causes' => [
                    'Format file tidak didukung.',
                    'Ukuran file melewati batas.',
                    'Input tidak memenuhi aturan validasi.',
                ],
                'steps' => [
                    'Sampaikan format dan ukuran yang didukung ke user.',
                    'Minta user memperbaiki input lalu mencoba ulang.',
                ],
            ],
            default => [
                'summary' => 'Error belum memiliki panduan khusus, tetapi metadata aman dan trace dapat dipakai untuk investigasi.',
                'causes' => [
                    'Kegagalan berasal dari fitur atau integrasi terkait.',
                    'Kode error belum diklasifikasikan secara spesifik.',
                ],
                'steps' => [
                    'Cari request ID pada log aplikasi.',
                    'Cek feature, status, latency, dan metadata pada modal ini.',
                    'Tambahkan klasifikasi khusus bila error ini sering muncul.',
                ],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function errorEventsQuery(array $filters = []): Builder
    {
        return $this->applyEventFilters(AIUsageEvent::query(), $filters)
            ->whereIn('status', [AIUsageEvent::STATUS_ERROR, AIUsageEvent::STATUS_BLOCKED]);
    }

    private function attachErrorAttributes(AIUsageEvent $event): AIUsageEvent
    {
        $severity = $this->errorSeverity($event);
        $guidance = $this->errorHandlingGuidance($event);

        $event->setAttribute('severity_level', $severity['level']);
        $event->setAttribute('severity_label', $severity['label']);
        $event->setAttribute('severity_tone', $severity['tone']);
        $event->setAttribute('severity_description', $severity['description']);
        $event->setAttribute('handling_summary', $guidance['summary']);
        $event->setAttribute('handling_causes', $guidance['causes']);
        $event->setAttribute('handling_steps', $guidance['steps']);

        return $event;
    }

    /**
     * User listing for the /admin/users page including computed presence
     * status and per-user activity counters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function userPresenceListing(array $filters = [], int $perPage = 15, ?CarbonInterface $now = null, ?int $page = null): LengthAwarePaginator
    {
        $now = $now ? $now->copy() : now();
        $perPage = max(1, min($perPage, self::RECENT_ROWS_LIMIT));
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
            ->paginate($perPage, ['*'], 'page', $page);

        if ($users->getCollection()->isEmpty()) {
            return $users;
        }

        $ids = $users->getCollection()->pluck('id')->all();

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

        $users->setCollection($users->getCollection()->map(function (User $user) use ($now, $eventsToday, $eventsWeek, $conversationCounts, $documentCounts, $memoCounts) {
            $user->setAttribute('presence_status', $this->presenceStatus($user, $now));
            $user->setAttribute('events_today', (int) ($eventsToday[$user->id] ?? 0));
            $user->setAttribute('events_week', (int) ($eventsWeek[$user->id] ?? 0));
            $user->setAttribute('conversation_count', (int) ($conversationCounts[$user->id] ?? 0));
            $user->setAttribute('document_count', (int) ($documentCounts[$user->id] ?? 0));
            $user->setAttribute('memo_count', (int) ($memoCounts[$user->id] ?? 0));

            return $user;
        }));

        return $users;
    }

    /**
     * Document listing for the /admin/documents page with status counters.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, status_counts: array<string, int>, mime_counts: array<string, int>, total_size_bytes: int}
     */
    public function documentListing(array $filters = [], int $perPage = 10, ?int $page = null): array
    {
        $perPage = max(1, min($perPage, self::RECENT_ROWS_LIMIT));

        $query = $this->applyDocumentFilters(Document::query(), $filters);

        $rows = (clone $query)
            ->select([
                'id',
                'user_id',
                'original_name',
                'mime_type',
                'file_size_bytes',
                'source_provider',
                'source_external_id',
                'source_synced_at',
                'status',
                'preview_html_path',
                'preview_status',
                'indexed_chunk_count',
                'embedding_provider',
                'indexed_at',
                'created_at',
                'updated_at',
            ])
            ->with('user:id,name,email')
            ->withCount('chunks')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $rows->setCollection(
            $rows->getCollection()->map(fn (Document $document) => $this->attachDocumentIndexAttributes($document))
        );

        $statusCounts = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->mapWithKeys(fn ($total, $status) => [(string) ($status ?: 'unknown') => (int) $total])
            ->all();

        $mimeCounts = (clone $query)
            ->selectRaw('mime_type, COUNT(*) as total')
            ->groupBy('mime_type')
            ->pluck('total', 'mime_type')
            ->mapWithKeys(fn ($total, $mime) => [(string) ($mime ?: 'unknown') => (int) $total])
            ->all();

        $totalSize = (int) (clone $query)->sum('file_size_bytes');

        return [
            'rows' => $rows,
            'status_counts' => $statusCounts,
            'mime_counts' => $mimeCounts,
            'total_size_bytes' => $totalSize,
        ];
    }

    public function documentDetail(?int $documentId): ?Document
    {
        if ($documentId === null || $documentId < 1) {
            return null;
        }

        $document = Document::query()
            ->select([
                'id',
                'user_id',
                'filename',
                'original_name',
                'file_path',
                'mime_type',
                'file_size_bytes',
                'source_provider',
                'source_external_id',
                'source_synced_at',
                'status',
                'preview_html_path',
                'preview_status',
                'indexed_chunk_count',
                'embedding_provider',
                'indexed_at',
                'created_at',
                'updated_at',
            ])
            ->with('user:id,name,email')
            ->withCount('chunks')
            ->find($documentId);

        return $document instanceof Document ? $this->attachDocumentIndexAttributes($document) : null;
    }

    private function attachDocumentIndexAttributes(Document $document): Document
    {
        $legacyChunkCount = (int) ($document->getAttribute('chunks_count') ?? 0);
        $indexedChunkCount = $document->indexed_chunk_count;
        $known = $indexedChunkCount !== null || $legacyChunkCount > 0;

        $document->setAttribute('display_chunk_count', $indexedChunkCount ?? $legacyChunkCount);
        $document->setAttribute('chunk_count_known', $known);

        return $document;
    }

    public function documentOwnerOptions(int $limit = 100): Collection
    {
        $limit = max(1, min($limit, self::RECENT_ROWS_LIMIT));

        return User::query()
            ->select(['id', 'name', 'email'])
            ->whereIn('id', Document::query()->select('user_id'))
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDocumentFilters(Builder $query, array $filters = []): Builder
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['type'])) {
            $mimeTypes = match ((string) $filters['type']) {
                'pdf' => ['application/pdf'],
                'csv' => ['text/csv', 'text/plain', 'application/csv'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                default => [],
            };

            if ($mimeTypes !== []) {
                $query->whereIn('mime_type', $mimeTypes);
            }
        }

        if (! empty($filters['search'])) {
            $query->where('original_name', 'like', '%'.trim((string) $filters['search']).'%');
        }

        [$start, $end] = $this->safeDateRange(
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
        );

        if ($start !== null) {
            $query->where('created_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('created_at', '<=', $end);
        }

        return $query;
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

        if (! empty($filters['exclude_lifecycle'])) {
            $query->where('action', '!=', AIUsageEvent::ACTION_STARTED);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['request_id'])) {
            $query->where('request_id', 'like', '%'.trim((string) $filters['request_id']).'%');
        }

        [$start, $end] = $this->safeDateRange(
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
        );

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
     * Parse a user-supplied date string safely. Returns null when the input
     * is empty or malformed.
     */
    public function safeParseDate(mixed $value): ?Carbon
    {
        return $this->parseDate($value);
    }

    /**
     * Parse and normalize a user-supplied date range.
     *
     * Date inputs from the UI are day-only strings, so their start/end
     * boundaries should include the whole selected day.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public function safeDateRange(mixed $startValue, mixed $endValue): array
    {
        $start = $this->parseDate($startValue);
        $end = $this->parseDate($endValue);

        if ($start !== null && $end !== null && $start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
            [$startValue, $endValue] = [$endValue, $startValue];
        }

        if ($start !== null) {
            $start = $this->applyDateOnlyBoundary($start, $startValue, 'start');
        }

        if ($end !== null) {
            $end = $this->applyDateOnlyBoundary($end, $endValue, 'end');
        }

        return [$start, $end];
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

    /**
     * @return array{total: int, success: int, failed: int}
     */
    private function eventStatusCountsBetween(CarbonInterface $start, CarbonInterface $end): array
    {
        $row = AIUsageEvent::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success', [AIUsageEvent::STATUS_SUCCESS])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as failed', [
                AIUsageEvent::STATUS_ERROR,
                AIUsageEvent::STATUS_BLOCKED,
            ])
            ->whereBetween('created_at', [$start, $end])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'success' => (int) ($row->success ?? 0),
            'failed' => (int) ($row->failed ?? 0),
        ];
    }

    private function averageLatencyBetween(CarbonInterface $start, CarbonInterface $end): ?int
    {
        return $this->roundedAverage(
            AIUsageEvent::query()
                ->whereBetween('created_at', [$start, $end])
                ->where('action', AIUsageEvent::ACTION_COMPLETED)
                ->where('status', AIUsageEvent::STATUS_SUCCESS)
                ->whereNotNull('latency_ms')
                ->avg('latency_ms')
        );
    }

    private function rate(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return ($part / $total) * 100;
    }

    /**
     * @return array<string, float|int|string|bool|null>
     */
    private function comparisonPayload(
        int|float|null $current,
        int|float|null $previous,
        bool $hasComparison,
        string $unit,
        bool $higherIsBetter,
    ): array {
        if (! $hasComparison || $current === null || $previous === null) {
            return [
                'current' => $current,
                'previous' => $previous,
                'delta' => null,
                'delta_percent' => null,
                'direction' => 'none',
                'tone' => 'neutral',
                'unit' => $unit,
                'has_comparison' => false,
            ];
        }

        $delta = $current - $previous;
        $direction = match (true) {
            $delta > 0 => 'up',
            $delta < 0 => 'down',
            default => 'flat',
        };

        $tone = match (true) {
            $direction === 'flat' => 'neutral',
            $higherIsBetter && $direction === 'up' => 'success',
            $higherIsBetter && $direction === 'down' => 'danger',
            ! $higherIsBetter && $direction === 'down' => 'success',
            ! $higherIsBetter && $direction === 'up' => 'danger',
            default => 'neutral',
        };

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'delta_percent' => $previous != 0 ? ($delta / abs($previous)) * 100 : null,
            'direction' => $direction,
            'tone' => $tone,
            'unit' => $unit,
            'has_comparison' => true,
        ];
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

    private function applyDateOnlyBoundary(Carbon $date, mixed $rawValue, string $boundary): Carbon
    {
        if (! is_string($rawValue) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($rawValue))) {
            return $date;
        }

        return $boundary === 'end'
            ? $date->endOfDay()
            : $date->startOfDay();
    }
}
