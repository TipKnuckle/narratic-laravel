<?php

namespace App\Console\Commands;

use App\Contracts\AudibleCatalog;
use App\Enums\Region;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature(
    'audible:details {asin : The Audible ASIN to fetch} {region=US : Region code (US or UK)}',
)]
#[Description('Fetch reviews and ratings for an audiobook from the Audible API')]
class AudiobookDetails extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $asin = $this->argument('asin');
        $region = Region::from($this->argument('region'));

        $catalog = app(AudibleCatalog::class);

        // Fetch ratings
        $this->info("Fetching ratings for {$asin} ({$region->value})...");
        $ratings = $catalog->fetchRatings([$asin], $region);

        if (empty($ratings)) {
            $this->warn('No ratings found.');
        } else {
            $rating = $ratings[0];
            $this->line(sprintf(
                '  Overall: %.2f (count: %d, ★1: %d, ★2: %d, ★3: %d, ★4: %d, ★5: %d)',
                $rating->overallAverage,
                $rating->overallCount,
                $rating->overallStar1,
                $rating->overallStar2,
                $rating->overallStar3,
                $rating->overallStar4,
                $rating->overallStar5,
            ));
            $this->line(sprintf(
                '  Story:  %.2f (count: %d, ★1: %d, ★2: %d, ★3: %d, ★4: %d, ★5: %d)',
                $rating->storyAverage,
                $rating->storyCount,
                $rating->storyStar1,
                $rating->storyStar2,
                $rating->storyStar3,
                $rating->storyStar4,
                $rating->storyStar5,
            ));
            $this->line(sprintf(
                '  Performance: %.2f (count: %d, ★1: %d, ★2: %d, ★3: %d, ★4: %d, ★5: %d)',
                $rating->performanceAverage,
                $rating->performanceCount,
                $rating->performanceStar1,
                $rating->performanceStar2,
                $rating->performanceStar3,
                $rating->performanceStar4,
                $rating->performanceStar5,
            ));
            $this->line("  Reviews count: {$rating->numReviews}");
            $this->line("  Zeroed: " . ($rating->isZeroed ? 'true' : 'false'));
        }

        // Fetch reviews
        $this->info("Fetching reviews for {$asin} ({$region->value})...");
        $reviews = $catalog->fetchReviews($asin, $region);

        if (empty($reviews)) {
            $this->warn('No reviews found.');
        } else {
            $this->table(
                ['ID', 'Author', 'Rating', 'Title', 'Date'],
                array_map(fn ($r) => [
                    $r->externalId,
                    $r->authorName,
                    $r->ratingOverall ?? '-',
                    Str::limit($r->title ?? '', 30),
                    $r->submittedAt,
                ], $reviews),
            );
        }
    }
}
