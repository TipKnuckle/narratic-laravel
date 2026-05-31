<?php

namespace App\Services;

use App\Contracts\AudibleCatalog;
use App\Enums\Region;
use App\Events\ReviewPublished;
use App\Models\Audiobook;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

class ReviewIngestionService
{
    public function __construct(
        private readonly AudibleCatalog $catalog,
    ) {}

    /**
     * Fetch and store new reviews for all audiobooks flagged reviews_pending.
     *
     * Returns an array keyed by outcome:
     *   - 'ingested' => count of new reviews stored
     *   - 'skipped'  => count of duplicate reviews (already stored)
     *   - 'errors'   => count of audiobooks where the API call failed
     *
     * Clears reviews_pending on every audiobook processed, regardless of
     * success or failure, so a failed fetch retries only when the ratings
     * sync re-flags it — never in a tight loop.
     */
    public function ingestPending(): array
    {
        $results = [
            'processed' => 0,
            'ingested' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        Audiobook::where('reviews_pending', true)->chunkById(50, function ($audiobooks) use (&$results) {
            foreach ($audiobooks as $audiobook) {
                try {
                    $outcome = $this->ingestOne($audiobook);
                } catch (\Throwable $e) {
                    $outcome = 'error';
                }

                $results['processed']++;

                if ($outcome === 'error') {
                    $results['errors']++;
                } else {
                    $results['ingested'] += $outcome['ingested'];
                    $results['skipped'] += $outcome['skipped'];
                }

                $audiobook->reviews_pending = false;
                $audiobook->save();
            }
        });

        return $results;
    }

    /**
     * Fetch and store new reviews for a single audiobook.
     *
     * @return array{ingested: int, skipped: int}|'error'
     */
    public function ingestOne(Audiobook $audiobook): array|string
    {
        try {
            $region = Region::from($audiobook->region);
            $reviews = $this->catalog->fetchReviews($audiobook->asin, $region);
        } catch (\Throwable $e) {
            return 'error';
        }

        if (empty($reviews)) {
            return ['ingested' => 0, 'skipped' => 0];
        }

        $externalIds = collect($reviews)->pluck('externalId')->filter()->unique()->values()->all();

        $existingExternalIds = Review::where('audiobook_id', $audiobook->id)
            ->where('source', 'audible')
            ->whereIn('external_id', $externalIds)
            ->pluck('external_id')
            ->all();

        $ingested = 0;
        $skipped = 0;
        $createdReviews = [];

        try {
            DB::transaction(function () use ($audiobook, $reviews, $existingExternalIds, &$ingested, &$skipped, &$createdReviews) {
                foreach ($reviews as $reviewResult) {
                    if (in_array($reviewResult->externalId, $existingExternalIds)) {
                        $skipped++;

                        continue;
                    }

                    $review = $audiobook->reviews()->create([
                        'external_id' => $reviewResult->externalId,
                        'source' => 'audible',
                        'format' => $reviewResult->format,
                        'author_name' => $reviewResult->authorName,
                        'title' => $reviewResult->title,
                        'body' => $reviewResult->format === 'freeform' ? $reviewResult->body : null,
                        'guided_responses' => $reviewResult->format === 'guided' ? $reviewResult->guidedResponses : null,
                        'rating_overall' => $reviewResult->ratingOverall,
                        'rating_story' => $reviewResult->ratingStory,
                        'rating_performance' => $reviewResult->ratingPerformance,
                        'related_url' => $reviewResult->relatedUrl,
                        'submitted_at' => $reviewResult->submittedAt,
                    ]);

                    $createdReviews[] = $review;
                    $ingested++;
                }
            });
        } catch (\Throwable $e) {
            return 'error';
        }

        foreach ($createdReviews as $review) {
            ReviewPublished::dispatch($review);
        }

        return ['ingested' => $ingested, 'skipped' => $skipped];
    }
}
