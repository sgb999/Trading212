<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('trading212:sync')
    ->everyMinute()
    ->weekdays()
    ->between('07:00', '17:00')
    ->timezone('Europe/London')
    ->withoutOverlapping(5);
