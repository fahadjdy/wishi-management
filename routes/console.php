<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wishi:auto-advance')->dailyAt('00:10');
Schedule::command('wishi:check-payments')->dailyAt('00:30');
Schedule::command('wishi:mark-missed --grace-days=14')->dailyAt('01:00');
Schedule::command('wishi:payment-reminders --days=3')->dailyAt('09:00');
Schedule::command('wishi:tender-reminders')->everyFifteenMinutes();
