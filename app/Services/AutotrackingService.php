<?php

namespace App\Services;

use App\Contracts\Membership;
use App\Events\NewReleaseAutoTracked;
use App\Models\AutotrackRule;
use App\Models\Tracking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AutotrackingService
{
    public function __construct(
        private readonly CatalogSearchService $catalogSearch,
        private readonly Membership $membership,
    ) {}

    /**
     * Run all autotrack rules and create auto trackings for new releases.
     *
     * Returns an array keyed by outcome:
     *   - 'tracked'    => count of new auto-trackings created
     *   - 'skipped'    => count of titles already tracked or outside window
     *   - 'errors'     => count of rule/search failures
     *   - 'rules_processed' => count of rules processed
     */
    public function runAll(): array
    {
        $results = [
            'tracked' => 0,
            'skipped' => 0,
            'errors' => 0,
            'rules_processed' => 0,
        ];

        AutotrackRule::with('user')->chunkById(50, function ($rules) use (&$results) {
            foreach ($rules as $rule) {
                try {
                    $outcome = $this->runRule($rule);
                } catch (\Throwable $e) {
                    $outcome = 'error';
                }

                $results['rules_processed']++;

                if ($outcome === 'error') {
                    $results['errors']++;
                } else {
                    $results['tracked'] += $outcome['tracked'];
                    $results['skipped'] += $outcome['skipped'];
                }
            }
        });

        return $results;
    }

    /**
     * Run a single autotrack rule for a single user.
     *
     * @return array{tracked: int, skipped: int}|'error'
     */
    public function runRule(AutotrackRule $rule): array|string
    {
        $user = $rule->user;

        if (! $this->membership->isActive($user)) {
            return ['tracked' => 0, 'skipped' => 0];
        }

        try {
            $audiobooks = $this->catalogSearch->searchAndIngest(
                $rule->search_type,
                $rule->term,
                $rule->region,
            );
        } catch (\Throwable $e) {
            return 'error';
        }

        if (empty($audiobooks)) {
            return ['tracked' => 0, 'skipped' => 0];
        }

        $windowDays = (int) config('narratic.autotracking.new_release_window_days', 7);
        $cutoff = CarbonImmutable::now()->subDays($windowDays);

        $existingAsins = Tracking::where('user_id', $user->id)
            ->whereIn('audiobook_id', array_map(fn ($a) => $a->id, $audiobooks))
            ->pluck('audiobook_id')
            ->all();

        $maxTracked = $this->membership->maxTracked($user);
        $currentTracked = Tracking::where('user_id', $user->id)->count();

        $tracked = 0;
        $skipped = 0;
        $created = [];

        DB::transaction(function () use ($audiobooks, $user, $cutoff, $existingAsins, $maxTracked, $currentTracked, &$tracked, &$skipped, &$created) {
            foreach ($audiobooks as $audiobook) {
                // Skip titles outside the new-release window
                if ($audiobook->published_at !== null && $audiobook->published_at->lte($cutoff)) {
                    $skipped++;

                    continue;
                }

                // Skip already-tracked titles
                if (in_array($audiobook->id, $existingAsins)) {
                    $skipped++;

                    continue;
                }

                // Respect membership cap
                if ($maxTracked !== PHP_INT_MAX && ($currentTracked + $tracked) >= $maxTracked) {
                    $skipped++;

                    continue;
                }

                $tracking = $user->trackings()->create([
                    'audiobook_id' => $audiobook->id,
                    'source' => 'auto',
                ]);

                $created[] = $tracking;
                $tracked++;
            }
        });

        foreach ($created as $tracking) {
            NewReleaseAutoTracked::dispatch($user, $tracking->audiobook);
        }

        return ['tracked' => $tracked, 'skipped' => $skipped];
    }
}
