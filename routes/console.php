<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ratings sync claimer tick. Runs frequently but only processes titles whose
// next_check_at has passed — the adaptive cadence (and per-title jitter) in
// docs/spec/05-adr-ratings-sync-cadence.md governs actual API volume, not this
// interval. withoutOverlapping so a slow run never stacks on the next tick.
Schedule::command('audiobook:sync-ratings')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Review ingestion sweep. Runs frequently but only touches titles flagged
// reviews_pending by the ratings sync. withoutOverlapping so a slow fetch
// never stacks.
Schedule::command('audiobook:ingest-reviews')
    ->everyFiveMinutes()
    ->withoutOverlapping();
