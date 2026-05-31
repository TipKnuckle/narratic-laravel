<?php

namespace App\Services;

use App\Contracts\AudibleCatalog;
use App\Dto\RatingResult;
use App\Enums\Region;
use App\Models\Audiobook;
use App\Models\RatingSnapshot;
use Carbon\CarbonImmutable;

class RatingsSyncService
{
    public function __construct(
        private readonly AudibleCatalog $catalog,
    ) {}

    /**
     * Sync ratings for a single audiobook.
     *
     * Returns one of: 'snapshot_created', 'unchanged', 'zeroed_skipped', 'no_data'.
     */
    public function syncOne(Audiobook $audiobook): string
    {
        $region = Region::from($audiobook->region);
        $results = $this->catalog->fetchRatings([$audiobook->asin], $region);

        if (empty($results)) {
            $this->stampChecked($audiobook);

            return 'no_data';
        }

        $rating = $results[0];

        if ($rating->isZeroed) {
            $audiobook->rating_zeroed_count = $audiobook->rating_zeroed_count + 1;
            $this->stampChecked($audiobook);

            return 'zeroed_skipped';
        }

        // Valid response after a zeroed streak — reset the counter.
        if ($audiobook->rating_zeroed_count > 0) {
            $audiobook->rating_zeroed_count = 0;
        }

        $latestSnapshot = $audiobook->ratingSnapshots()
            ->latest('recorded_at')
            ->with('aspectSnapshots')
            ->first();

        $previousNumReviews = $latestSnapshot?->num_reviews ?? 0;

        if ($latestSnapshot !== null && ! $this->hasChanged($rating, $latestSnapshot)) {
            // Ratings unchanged — check if review count rose anyway.
            if ($rating->numReviews > $previousNumReviews) {
                $audiobook->reviews_pending = true;
            }

            $this->stampChecked($audiobook);

            return 'unchanged';
        }

        // Ratings changed — write a new snapshot.
        $snapshot = $audiobook->ratingSnapshots()->create([
            'recorded_at' => CarbonImmutable::now(),
            'num_reviews' => $rating->numReviews,
        ]);

        $snapshot->aspectSnapshots()->createMany([
            [
                'aspect' => 'overall',
                'average' => $rating->overallAverage,
                'count' => $rating->overallCount,
                'star_1' => $rating->overallStar1,
                'star_2' => $rating->overallStar2,
                'star_3' => $rating->overallStar3,
                'star_4' => $rating->overallStar4,
                'star_5' => $rating->overallStar5,
            ],
            [
                'aspect' => 'story',
                'average' => $rating->storyAverage,
                'count' => $rating->storyCount,
                'star_1' => $rating->storyStar1,
                'star_2' => $rating->storyStar2,
                'star_3' => $rating->storyStar3,
                'star_4' => $rating->storyStar4,
                'star_5' => $rating->storyStar5,
            ],
            [
                'aspect' => 'performance',
                'average' => $rating->performanceAverage,
                'count' => $rating->performanceCount,
                'star_1' => $rating->performanceStar1,
                'star_2' => $rating->performanceStar2,
                'star_3' => $rating->performanceStar3,
                'star_4' => $rating->performanceStar4,
                'star_5' => $rating->performanceStar5,
            ],
        ]);

        // If the review count rose, flag for review ingestion.
        if ($rating->numReviews > $previousNumReviews) {
            $audiobook->reviews_pending = true;
        }

        $this->stampChecked($audiobook);

        return 'snapshot_created';
    }

    /**
     * Compare a fresh RatingResult against the latest stored snapshot.
     * Returns true if any aspect's average, count, or star distribution differs.
     */
    private function hasChanged(RatingResult $rating, RatingSnapshot $snapshot): bool
    {
        $aspects = $snapshot->aspectSnapshots->keyBy('aspect');

        $current = [
            'overall' => [
                'average' => $rating->overallAverage,
                'count' => $rating->overallCount,
                'star_1' => $rating->overallStar1,
                'star_2' => $rating->overallStar2,
                'star_3' => $rating->overallStar3,
                'star_4' => $rating->overallStar4,
                'star_5' => $rating->overallStar5,
            ],
            'story' => [
                'average' => $rating->storyAverage,
                'count' => $rating->storyCount,
                'star_1' => $rating->storyStar1,
                'star_2' => $rating->storyStar2,
                'star_3' => $rating->storyStar3,
                'star_4' => $rating->storyStar4,
                'star_5' => $rating->storyStar5,
            ],
            'performance' => [
                'average' => $rating->performanceAverage,
                'count' => $rating->performanceCount,
                'star_1' => $rating->performanceStar1,
                'star_2' => $rating->performanceStar2,
                'star_3' => $rating->performanceStar3,
                'star_4' => $rating->performanceStar4,
                'star_5' => $rating->performanceStar5,
            ],
        ];

        foreach (['overall', 'story', 'performance'] as $aspect) {
            $stored = $aspects->get($aspect);

            if ($stored === null) {
                return true; // missing aspect row counts as changed
            }

            if ((float) $stored->average !== $current[$aspect]['average']) {
                return true;
            }

            if ((int) $stored->count !== $current[$aspect]['count']) {
                return true;
            }

            for ($star = 1; $star <= 5; $star++) {
                if ((int) $stored->{'star_'.$star} !== $current[$aspect]["star_{$star}"]) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Stamp the title as just-checked and schedule its next check.
     *
     * Called on every sync outcome so a title is always rescheduled, never
     * left perpetually "due". Persists the audiobook.
     */
    private function stampChecked(Audiobook $audiobook): void
    {
        $audiobook->ratings_synced_at = CarbonImmutable::now();
        $audiobook->next_check_at = $this->nextCheckAt($audiobook);
        $audiobook->save();
    }

    /**
     * When this title should next be checked, with jitter applied.
     *
     * The base interval comes from the adaptive cadence
     * (docs/spec/05-adr-ratings-sync-cadence.md); jitter spreads re-checks
     * across the day so they don't clump into a single burst.
     */
    private function nextCheckAt(Audiobook $audiobook): CarbonImmutable
    {
        $baseHours = $this->baseCheckIntervalDays($audiobook) * 24;
        $jitterPct = (float) config('narratic.ratings_sync.jitter_pct', 0.15);

        $spread = (int) round($baseHours * $jitterPct);
        $offset = $spread > 0 ? random_int(-$spread, $spread) : 0;

        return CarbonImmutable::now()->addHours($baseHours + $offset);
    }

    /**
     * Base interval in days until the next ratings check, per the cadence in
     * docs/spec/05-adr-ratings-sync-cadence.md. Pure (no jitter), so it is
     * exactly assertable.
     *
     * Newly published titles stay at the daily floor. Otherwise the interval
     * grows with the number of days the ratings have been unchanged (i.e. days
     * since the latest snapshot was recorded — snapshots are written only on
     * change).
     */
    public function baseCheckIntervalDays(Audiobook $audiobook): int
    {
        /** @var array{newness_days:int, bands:array<int,int>} $config */
        $config = config('narratic.ratings_sync');

        // New releases are the most volatile — keep them at the daily floor.
        if ($audiobook->published_at !== null
            && $audiobook->published_at->diffInDays(CarbonImmutable::now()) < $config['newness_days']) {
            return $this->intervalForDaysUnchanged($config['bands'], 0);
        }

        $latest = $audiobook->ratingSnapshots()->latest('recorded_at')->first();

        // Past the newness window with no ratings history at all — a title that
        // never gained traction (the cucumber cookbook nobody buys). Let it
        // drift to the cold cap rather than checking a dead listing daily
        // forever. Within the newness window it was already returned above.
        if ($latest === null) {
            return $this->intervalForDaysUnchanged($config['bands'], PHP_INT_MAX);
        }

        $daysUnchanged = (int) $latest->recorded_at->diffInDays(CarbonImmutable::now());

        return $this->intervalForDaysUnchanged($config['bands'], $daysUnchanged);
    }

    /**
     * Look up the check interval (days) for a given number of days-unchanged:
     * the value of the largest band threshold that is <= daysUnchanged.
     *
     * @param  array<int,int>  $bands  threshold-days => interval-days
     */
    private function intervalForDaysUnchanged(array $bands, int $daysUnchanged): int
    {
        ksort($bands);

        $interval = (int) reset($bands);

        foreach ($bands as $threshold => $days) {
            if ($daysUnchanged >= $threshold) {
                $interval = (int) $days;
            }
        }

        return $interval;
    }
}
