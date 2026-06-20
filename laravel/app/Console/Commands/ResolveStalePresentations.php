<?php

namespace App\Console\Commands;

use App\Models\Presentation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ResolveStalePresentations extends Command
{
    protected $signature = 'presentations:resolve-stale-renders
                            {--minutes=10 : Mark presentation renders as failed if unchanged after N minutes}';

    protected $description = 'Mark stale presentation render jobs as failed so users can retry instead of seeing an endless pending state.';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $stalePresentations = Presentation::query()
            ->whereIn('status', [Presentation::STATUS_PENDING, Presentation::STATUS_PROCESSING])
            ->where('updated_at', '<=', $cutoff)
            ->get(['id', 'status', 'updated_at']);

        if ($stalePresentations->isEmpty()) {
            $this->info('No stale presentation renders found.');

            return self::SUCCESS;
        }

        $resolved = 0;
        $skippedActiveClaims = 0;
        $message = 'Render presentasi tidak selesai dalam batas waktu. Silakan kirim ulang.';

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
                    'status' => Presentation::STATUS_ERROR,
                    'error_message' => $message,
                    'updated_at' => now(),
                ]);

            $resolved += $updated;
        }

        $this->info("Resolved {$resolved} stale presentation render(s) older than {$minutes} minute(s).");
        if ($skippedActiveClaims > 0) {
            $this->info("Skipped {$skippedActiveClaims} stale presentation render(s) with active claim.");
        }

        Log::info('ResolveStalePresentations completed', [
            'resolved' => $resolved,
            'skipped_active_claims' => $skippedActiveClaims,
            'minutes_threshold' => $minutes,
        ]);

        return self::SUCCESS;
    }
}
