<?php

namespace App\Console\Commands;

use App\Models\Audiobook;
use App\Services\RatingsSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    'audiobook:sync-ratings
        {--asin= : Single ASIN to sync (requires --region)}
        {--region= : Region for single-ASIN sync (US|UK)}',
)]
#[Description('Sync aggregate ratings from Audible for audiobooks due for a refresh')]
class SyncRatings extends Command
{
    public function handle(RatingsSyncService $sync): void
    {
        $asin = $this->option('asin');
        $region = $this->option('region');

        if ($asin !== null && $region !== null) {
            $this->syncSingle($sync, $asin, $region);

            return;
        }

        if ($asin xor $region) {
            $this->error('Both --asin and --region are required when targeting a single title.');

            return;
        }

        $this->syncAllDue($sync);
    }

    private function syncSingle(RatingsSyncService $sync, string $asin, string $region): void
    {
        $audiobook = Audiobook::where('asin', $asin)
            ->where('region', $region)
            ->first();

        if ($audiobook === null) {
            $this->warn("Audiobook {$asin} ({$region}) not found in the database.");

            return;
        }

        $this->info("Syncing {$asin} ({$region})...");
        $result = $sync->syncOne($audiobook);
        $this->line("  → {$result}");
    }

    private function syncAllDue(RatingsSyncService $sync): void
    {
        $due = Audiobook::dueForRatingsSync()->get();
        $count = $due->count();

        if ($count === 0) {
            $this->info('No audiobooks due for a ratings sync.');

            return;
        }

        $this->info("Syncing {$count} audiobook(s)...");

        $results = [
            'snapshot_created' => 0,
            'unchanged' => 0,
            'zeroed_skipped' => 0,
            'no_data' => 0,
        ];

        foreach ($due as $audiobook) {
            $result = $sync->syncOne($audiobook);
            $results[$result]++;
            $this->line("  {$audiobook->asin} ({$audiobook->region}): {$result}");
        }

        $this->line('');
        $this->table(
            ['Result', 'Count'],
            array_map(fn (string $key, int $val) => [$key, $val], array_keys($results), array_values($results)),
        );
    }
}
