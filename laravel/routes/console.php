<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('documents:purge-deleted --days=7')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('chat:resolve-stale-responses --minutes=10')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
