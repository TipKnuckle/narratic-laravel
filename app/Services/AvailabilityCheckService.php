<?php

namespace App\Services;

use App\Contracts\AudibleCatalog;
use App\Enums\Region;
use App\Events\BecameAvailable;
use App\Events\BecameUnavailable;
use App\Models\Audiobook;
use App\Models\Tracking;
use Carbon\CarbonImmutable;

class AvailabilityCheckService
{
    public function __construct(
        private readonly AudibleCatalog $catalog,
    ) {}

    /**
     * Check availability for all audiobooks due for a probe.
     *
     * Returns an array keyed by outcome:
     *   - 'checked'     => count of titles probed
     *   - 'available'   => count confirmed available
     *   - 'unavailable' => count newly marked unavailable
     *   - 'strike'      => count where a failure incremented strikes
     *   - 'recovered'   => count that returned to available
     */
    public function checkPending(): array
    {
        $results = [
            'checked' => 0,
            'available' => 0,
            'unavailable' => 0,
            'strike' => 0,
            'recovered' => 0,
        ];

        Audiobook::whereHas('trackings')
            ->dueForAvailabilityCheck()
            ->chunkById(50, function ($audiobooks) use (&$results) {
                foreach ($audiobooks as $audiobook) {
                    try {
                        $outcome = $this->checkOne($audiobook);
                    } catch (\Throwable $e) {
                        $outcome = 'error';
                    }

                    $results['checked']++;

                    if ($outcome === 'error') {
                        $results['strike']++;
                    } elseif ($outcome === 'available') {
                        $results['available']++;
                    } elseif ($outcome === 'recovered') {
                        $results['recovered']++;
                    } elseif ($outcome === 'unavailable') {
                        $results['unavailable']++;
                    } else {
                        $results['strike']++;
                    }
                }
            });

        return $results;
    }

    /**
     * Check availability for a single audiobook.
     *
     * @return 'available'|'unavailable'|'strike'|'recovered'|'error'
     */
    public function checkOne(Audiobook $audiobook): string
    {
        $region = Region::from($audiobook->region);

        try {
            $results = $this->catalog->fetchRatings([$audiobook->asin], $region);
        } catch (\Throwable $e) {
            $this->stampChecked($audiobook);

            return 'error';
        }

        $found = ! empty($results);

        if ($found) {
            return $this->handleFound($audiobook);
        }

        return $this->handleNotFound($audiobook);
    }

    /**
     * The product was found in the catalog — it's available.
     *
     * If it was previously unavailable, mark it recovered.
     */
    private function handleFound(Audiobook $audiobook): string
    {
        $wasUnavailable = $audiobook->availability === 'unavailable';

        $audiobook->unavailable_strikes = 0;

        if ($wasUnavailable) {
            $audiobook->availability = 'available';
            $audiobook->unavailable_since = null;

            $audiobook->availabilityEvents()->create([
                'from_state' => 'unavailable',
                'to_state' => 'available',
                'occurred_at' => CarbonImmutable::now(),
            ]);

            BecameAvailable::dispatch($audiobook);

            $this->stampChecked($audiobook);

            return 'recovered';
        }

        $this->stampChecked($audiobook);

        return 'available';
    }

    /**
     * The product was not found in the catalog.
     *
     * Increment strikes. If strikes cross the threshold, mark it unavailable.
     */
    private function handleNotFound(Audiobook $audiobook): string
    {
        $threshold = (int) config('narratic.availability_check.strike_threshold', 2);
        $audiobook->unavailable_strikes = $audiobook->unavailable_strikes + 1;

        if ($audiobook->unavailable_strikes >= $threshold) {
            $wasUnavailable = $audiobook->availability === 'unavailable';

            $audiobook->availability = 'unavailable';
            $audiobook->unavailable_since ??= CarbonImmutable::now();

            if (! $wasUnavailable) {
                $audiobook->availabilityEvents()->create([
                    'from_state' => 'available',
                    'to_state' => 'unavailable',
                    'occurred_at' => CarbonImmutable::now(),
                ]);

                BecameUnavailable::dispatch($audiobook);
            }

            $this->stampChecked($audiobook);

            return 'unavailable';
        }

        $this->stampChecked($audiobook);

        return 'strike';
    }

    /**
     * Stamp the title as just-checked and schedule its next check.
     *
     * Called on every probe outcome so a title is always rescheduled, never
     * left perpetually "due". Persists the audiobook.
     */
    private function stampChecked(Audiobook $audiobook): void
    {
        $audiobook->availability_checked_at = CarbonImmutable::now();
        $audiobook->next_availability_check_at = $this->nextCheckAt();
        $audiobook->save();
    }

    /**
     * When this title should next be checked, with jitter applied.
     *
     * Fixed interval (7 days by default) plus ±jitter to spread re-checks
     * across the week.
     */
    private function nextCheckAt(): CarbonImmutable
    {
        $intervalDays = (int) config('narratic.availability_check.interval_days', 7);
        $jitterPct = (float) config('narratic.availability_check.jitter_pct', 0.15);

        $baseHours = $intervalDays * 24;
        $spread = (int) round($baseHours * $jitterPct);
        $offset = $spread > 0 ? random_int(-$spread, $spread) : 0;

        return CarbonImmutable::now()->addHours($baseHours + $offset);
    }

    /**
     * Remove trackings for titles that have been unavailable past the decay
     * threshold.
     *
     * Returns the number of trackings deleted.
     */
    public function decayStaleTrackings(): int
    {
        $thresholdDays = (int) config('narratic.decay.unavailable_days', 180);
        $cutoff = CarbonImmutable::now()->subDays($thresholdDays);

        $staleAudiobookIds = Audiobook::where('availability', 'unavailable')
            ->where('unavailable_since', '<=', $cutoff)
            ->pluck('id')
            ->all();

        if (empty($staleAudiobookIds)) {
            return 0;
        }

        return Tracking::whereIn('audiobook_id', $staleAudiobookIds)
            ->delete();
    }
}
