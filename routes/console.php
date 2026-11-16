<?php

use App\Jobs\SnapshotPortfolios;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('trading212:sync')->everyMinute()->withoutOverlapping(5);

foreach (['08:05', '16:40'] as $snapshotTime) {
    Schedule::job(new SnapshotPortfolios)
        ->name("portfolio-snapshots-{$snapshotTime}")
        ->weekdays()
        ->at($snapshotTime)
        ->timezone('Europe/London')
        ->onOneServer();
}
Schedule::command('trading212:sync')
    ->everyMinute()
    ->weekdays()
    ->between('07:00', '17:00')
    ->timezone('Europe/London')
    ->withoutOverlapping(5);
