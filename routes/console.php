<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('items:check-unclaimed')->daily();
Schedule::command('claims:check-pickup-deadlines')->daily();
Schedule::command('trust:passive-recovery')->cron('0 */5 * * *');
Schedule::command('tokens:cleanup')->hourly();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
