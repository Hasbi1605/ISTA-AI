<?php

namespace App\Livewire\Admin;

use App\Services\Admin\AdminMetricsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin', ['title' => 'Overview', 'heading' => 'Overview'])]
class AdminDashboard extends Component
{
    /**
     * Activity range in days for the chart and feature breakdown.
     */
    public int $rangeDays = AdminMetricsService::DEFAULT_RANGE_DAYS;

    public function setRange(int $days): void
    {
        $allowed = [7, 14, 30];

        if (! in_array($days, $allowed, true)) {
            $days = AdminMetricsService::DEFAULT_RANGE_DAYS;
        }

        $this->rangeDays = $days;
    }

    public function refreshMetrics(): void
    {
        // Placeholder action so the dashboard can re-render on demand
        // without persisting state. Used by the "Refresh" button.
    }

    public function render(AdminMetricsService $metrics)
    {
        $kpis = $metrics->overviewKpis();
        $series = $metrics->dailyActivitySeries($this->rangeDays);
        $distribution = $metrics->featureDistribution(
            now()->subDays($this->rangeDays - 1)->startOfDay(),
            now(),
        );
        $recentEvents = $metrics->recentEvents([], 10);
        $recentErrors = $metrics->recentErrors([], 5);

        return view('livewire.admin.admin-dashboard', [
            'kpis' => $kpis,
            'series' => $series,
            'distribution' => $distribution,
            'recentEvents' => $recentEvents,
            'recentErrors' => $recentErrors,
            'rangeDays' => $this->rangeDays,
            'maxSeriesValue' => max(1, collect($series)->max('total') ?: 1),
        ]);
    }
}
