<?php

namespace App\Console\Commands;

use App\Models\Audiobook;
use App\Services\ReviewIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    'audiobook:ingest-reviews
        {--asin= : Single ASIN to ingest reviews for (requires --region)}
        {--region= : Region for single-ASIN ingestion (US|UK)}',
)]
#[Description('Fetch and store new reviews from Audible for titles flagged reviews_pending')]
class IngestReviews extends Command
{
    public function handle(ReviewIngestionService $ingestion): void
    {
        $asin = $this->option('asin');
        $region = $this->option('region');

        if ($asin !== null && $region !== null) {
            $this->ingestSingle($ingestion, $asin, $region);

            return;
        }

        if ($asin xor $region) {
            $this->error('Both --asin and --region are required when targeting a single title.');

            return;
        }

        $this->ingestAllPending($ingestion);
    }

    private function ingestSingle(ReviewIngestionService $ingestion, string $asin, string $region): void
    {
        $audiobook = Audiobook::where('asin', $asin)
            ->where('region', $region)
            ->first();

        if ($audiobook === null) {
            $this->warn("Audiobook {$asin} ({$region}) not found in the database.");

            return;
        }

        $this->info("Ingesting reviews for {$asin} ({$region})...");
        $result = $ingestion->ingestOne($audiobook);
        $this->line('  → '.match (true) {
            is_string($result) => $result,
            default => "{$result['ingested']} ingested, {$result['skipped']} skipped",
        });
    }

    private function ingestAllPending(ReviewIngestionService $ingestion): void
    {
        $pendingCount = Audiobook::where('reviews_pending', true)->count();

        if ($pendingCount === 0) {
            $this->info('No audiobooks with reviews_pending flag set.');

            return;
        }

        $this->info("Ingesting reviews for {$pendingCount} audiobook(s)...");

        $results = $ingestion->ingestPending();

        $this->line('');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $results['processed']],
                ['Ingested', $results['ingested']],
                ['Skipped (duplicates)', $results['skipped']],
                ['Errors', $results['errors']],
            ],
        );
    }
}
