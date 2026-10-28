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

// Sales + quotas come from NetSuite only (no Salesforce calls) — keep near-real-time.
Schedule::command('castle:refresh --only=sales')
    ->everyFiveMinutes()
    ->weekdays()
    ->between('8:00', '20:00')
    ->timezone($tz)
    ->withoutOverlapping(10);

Schedule::command('castle:refresh --only=sales')
    ->everyThirtyMinutes()
    ->timezone($tz)
    ->when(fn () => ! $isWorkingHours())
    ->withoutOverlapping(20);

// Quotas load from the committed JSON (NetSuite 2026.2 dropped the quota record).
Schedule::command('castle:refresh --only=quotas')
    ->dailyAt('07:30')
    ->timezone($tz)
    ->withoutOverlapping(10);

// Activities come from Salesforce. Counts move slowly, so a coarser cadence
// (15 min in business hours, 30 min off-hours) cuts Salesforce API volume ~85%.
Schedule::command('castle:refresh --only=activities')
    ->everyFifteenMinutes()
    ->weekdays()
    ->between('8:00', '20:00')
    ->timezone($tz)
    ->withoutOverlapping(15);

Schedule::command('castle:refresh --only=activities')
    ->everyThirtyMinutes()
    ->timezone($tz)
    ->when(fn () => ! $isWorkingHours())
    ->withoutOverlapping(20);

// ── DIFOT cache warm (task 363) ──────────────────────────────────────────────
// Warm the /operaciones DIFOT cards (NetSuite: 5 SuiteQL + RESTlet script 1503)
// on their own staggered cadence so page loads always read a warm DB cache and
// never call NetSuite directly. TTL is 45 min (see DifotService); warming every
// 15 min keeps a comfortable margin. Minutes 7/22/37/52 sit OFF the 5-minute
// sales-refresh marks to minimise NetSuite account-wide concurrency collisions.
Schedule::command('castle:refresh --only=difot')
    ->cron('7,22,37,52 * * * *')
    ->timezone($tz)
    ->withoutOverlapping(15);

// ── Operaciones delivery-split cache warm ────────────────────────────────────
// Warm the /operaciones "Monto por entregar" totals + by-rep split (NetSuite
// SuiteQL via RESTlet 1503) so page loads read a warm DB cache with stale
// fallback. Same 15-min cadence as DIFOT; minutes 4/19/34/49 sit off the sales
// (0/5/...), DIFOT (7/22/37/52) and pipeline (11/26/41/56) marks.
Schedule::command('castle:refresh --only=operaciones')
    ->cron('4,19,34,49 * * * *')
    ->timezone($tz)
    ->withoutOverlapping(15);

// Salesforce pipeline warm — same idea, offset again to 11/26/41/56 so it does
// not collide with the DIFOT or sales/activities jobs.
Schedule::command('castle:refresh --only=pipeline')
    ->cron('11,26,41,56 * * * *')
    ->timezone($tz)
    ->withoutOverlapping(15);

// ── Monthly dashboard screenshot email (task 246, requested by Luciano) ───────
// 23:00 on the LAST calendar day of each month (ART). Gated by the
// MONTHLY_DASHBOARD_EMAIL_ENABLED env flag so it stays off until enabled.
Schedule::command('dashboards:email-monthly')
    ->dailyAt('23:00')
    ->timezone($tz)
    ->when(fn () => now($tz)->isLastOfMonth())
    ->withoutOverlapping(30);

// ── Follow-up circuit: surface chase-due tasks (Stage C) ─────────────────────
// Each weekday morning (ART), flip any follow-up whose reply window lapsed with
// no response to queued + due-today, so the autonomy loop drafts a reminder to
// the contact in Peter's voice. See memory followup-circuit.
Schedule::command('comms:chase-due --surface')
    ->weekdays()
    ->dailyAt('07:30')
    ->timezone($tz)
    ->withoutOverlapping(30);
