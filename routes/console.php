<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Automatic dashboard refresh (NetSuite + Salesforce) ──────────────────────
// Argentine business hours (Mon–Fri 08:00–20:00 ART): every 5 minutes.
// Outside those hours (nights + weekends): every 30 minutes.
$tz = 'America/Argentina/Buenos_Aires';

$isWorkingHours = function () use ($tz): bool {
    $now = now($tz);
    return $now->isWeekday() && $now->hour >= 8 && $now->hour < 20;
};

Schedule::command('castle:refresh')
    ->everyFiveMinutes()
    ->weekdays()
    ->between('8:00', '20:00')
    ->timezone($tz)
    ->withoutOverlapping(10);

Schedule::command('castle:refresh')
    ->everyThirtyMinutes()
    ->timezone($tz)
    ->when(fn () => ! $isWorkingHours())
    ->withoutOverlapping(20);
