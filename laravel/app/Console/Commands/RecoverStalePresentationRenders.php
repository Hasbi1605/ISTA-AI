<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePresentation;
use App\Models\Presentation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RecoverStalePresentationRenders extends Command
{
    protected $signature = 'presentations:recover-stale-renders
                            {--minutes= : Redispatch presentation renders unchanged after N minutes}
                            {--limit= : Maximum stale renders to redispatch}';

    protected $description = 'Redispatch stale presentation render jobs that were never consumed by the queue worker.';

    public function handle(): int
    {
        $minutes = max(1, (int) ($this->option('minutes') ?: config('presentations.deploy_recovery.minutes', 1)));
        $limit = max(1, min(100, (int) ($this->option('limit') ?: config('presentations.deploy_recovery.limit', 25))));
        $cutoff = now()->subMinutes($minutes);

        $stalePresentations = Presentation::query()
            ->whereIn('status', [Presentation::STATUS_PENDING, Presentation::STATUS_PROCESSING])
            ->where('updated_at', '<=', $cutoff)
            ->oldest('updated_at')
            ->limit($limit)
            ->get(['id', 'status', 'updated_at']);

        if ($stalePresentations->isEmpty()) {
            $this->info('No stale presentation renders found for recovery.');

            return self::SUCCESS;
        }

        $recovered = 0;
        $skippedActiveClaims = 0;

        foreach ($stalePresentations as $presentation) {
            $claimKey = 'presentation_generate_claim:'.$presentation->id;

            if ($presentation->status === Presentation::STATUS_PROCESSING && Cache::has($claimKey)) {
                $skippedActiveClaims++;

                continue;
            }

            Cache::forget($claimKey);

            $updated = Presentation::query()
                ->whereKey($presentation->id)
                ->whereIn('status', [Presentation::STATUS_PENDING, Presentation::STATUS_PROCESSING])
                ->where('updated_at', '<=', $cutoff)
                ->update([
                    'status' => Presentation::STATUS_PENDING,
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                continue;
            }

            $fresh = Presentation::query()->find($presentation->id);
            if ($fresh === null) {
                continue;
            }

            GeneratePresentation::dispatch($fresh);
            $recovered++;
        }

        $this->info("Recovered {$recovered} stale presentation render(s) older than {$minutes} minute(s).");
        if ($skippedActiveClaims > 0) {
            $this->info("Skipped {$skippedActiveClaims} stale presentation render(s) with active claim.");
        }

        Log::info('RecoverStalePresentationRenders completed', [
            'recovered' => $recovered,
            'skipped_active_claims' => $skippedActiveClaims,
            'minutes_threshold' => $minutes,
            'limit' => $limit,
        ]);

        return self::SUCCESS;
    }
}
