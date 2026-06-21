<?php

namespace App\Providers;

use App\Console\Commands\CompatibleHorizonWorkCommand;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Console\WorkCommand as HorizonWorkCommand;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $compatibleWorkCommand = function ($app) {
            return new CompatibleHorizonWorkCommand($app['queue.worker'], $app['cache.store']);
        };

        $this->app->bind(HorizonWorkCommand::class, $compatibleWorkCommand);
        $this->app->bind(CompatibleHorizonWorkCommand::class, $compatibleWorkCommand);
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            return $user?->isSuperAdmin() === true
                && $user->isActive()
                && ! (bool) $user->force_password_change;
        });
    }
}
