<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ratings Sync Cadence
    |--------------------------------------------------------------------------
    |
    | How often each tracked title is checked for new ratings. The full
    | rationale lives in docs/spec/05-adr-ratings-sync-cadence.md. In short:
    | a daily ceiling, backing off toward a 5-day floor as a title's ratings
    | go quiet, keyed on how many days since they last actually changed.
    |
    */

    'ratings_sync' => [

        // Titles published within this many days stay at the daily floor
        // regardless of how quiet they are — new releases are most volatile.
        'newness_days' => (int) env('NARRATIC_RATINGS_NEWNESS_DAYS', 90),

        // Random ± spread applied to next_check_at so re-checks don't clump
        // into a single daily burst. 0.15 = ±15%.
        'jitter_pct' => (float) env('NARRATIC_RATINGS_JITTER_PCT', 0.15),

        // Max titles a single scheduler tick claims and dispatches. Sizing
        // rule: tick_frequency × batch_limit >= daily eligible count.
        'batch_limit' => (int) env('NARRATIC_RATINGS_BATCH_LIMIT', 200),

        // days-since-last-change => interval in days until next check.
        // The interval is the value of the largest threshold <= days-unchanged.
        // These are the legacy WordPress system's proven values.
        'bands' => [
            0 => 1,   // < 30 days quiet: check daily
            30 => 3,
            60 => 4,
            120 => 5, // cold cap — never longer than 5 days
        ],

    ],

    /*

    |--------------------------------------------------------------------------
    | Availability Checking Cadence
    |--------------------------------------------------------------------------
    |
    | How often each tracked title is probed for availability. The full
    | rationale lives in docs/spec/06-adr-availability-checking-pattern.md.
    | Same claimer-tick pattern as Ratings Sync — a frequent scheduler tick
    | drains titles whose next_availability_check_at has passed.
    |
    */

    'availability_check' => [
        'interval_days' => (int) env('NARRATIC_AVAILABILITY_INTERVAL_DAYS', 7),
        'jitter_pct' => (float) env('NARRATIC_AVAILABILITY_JITTER_PCT', 0.15),
        'batch_limit' => (int) env('NARRATIC_AVAILABILITY_BATCH_LIMIT', 200),
        'strike_threshold' => (int) env('NARRATIC_AVAILABILITY_STRIKE_THRESHOLD', 2),
    ],

    /*

    |--------------------------------------------------------------------------
    | Unavailability Decay
    |--------------------------------------------------------------------------
    |
    | When a title has been unavailable for this many days, its trackings are
    | automatically removed. This prevents stale unavailable titles from
    | accumulating in member libraries indefinitely.
    |
    */

    'decay' => [
        'unavailable_days' => (int) env('NARRATIC_DECAY_UNAVAILABLE_DAYS', 180),
    ],

    /*

    |--------------------------------------------------------------------------
    | Digests
    |--------------------------------------------------------------------------
    |
    | Controls digest assembly behaviour: initial lookback for new users,
    | and the age gate for review freshness.
    |
    */

    'digests' => [
        'initial_lookback_days' => (int) env('NARRATIC_DIGESTS_INITIAL_LOOKBACK_DAYS', 7),
        'review_age_gate_days' => (int) env('NARRATIC_DIGESTS_REVIEW_AGE_GATE_DAYS', 30),
    ],

];
