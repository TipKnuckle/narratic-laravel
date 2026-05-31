<?php

use App\Contracts\AudibleCatalog;
use App\Dto\RatingResult;
use App\Enums\Region;
use App\Models\Audiobook;
use App\Services\RatingsSyncService;

uses()->group('feature');

beforeEach(function () {
    $this->audiobook = Audiobook::factory()->create([
        'asin' => 'B07DXYZ123',
        'region' => 'US',
        'rating_zeroed_count' => 0,
        'reviews_pending' => false,
    ]);
});

it('creates a snapshot when no previous snapshot exists', function () {
    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 100,
        overallAverage: 4.5, overallCount: 80,
        overallStar1: 5, overallStar2: 10, overallStar3: 15, overallStar4: 20, overallStar5: 30,
        storyAverage: 4.3, storyCount: 75,
        storyStar1: 8, storyStar2: 12, storyStar3: 18, storyStar4: 22, storyStar5: 25,
        performanceAverage: 4.7, performanceCount: 70,
        performanceStar1: 2, performanceStar2: 5, performanceStar3: 10, performanceStar4: 18, performanceStar5: 35,
        isZeroed: false,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new RatingsSyncService($catalog);
    $result = $service->syncOne($this->audiobook);

    expect($result)->toBe('snapshot_created');

    $this->audiobook->refresh();
    expect($this->audiobook->ratingSnapshots)->toHaveCount(1);
    expect($this->audiobook->ratingSnapshots[0]->aspectSnapshots)->toHaveCount(3);
    expect($this->audiobook->ratings_synced_at)->not->toBeNull();
});

it('creates a snapshot when ratings have changed', function () {
    $existingSnapshot = $this->audiobook->ratingSnapshots()->create([
        'recorded_at' => now()->subDay(),
        'num_reviews' => 90,
    ]);

    $existingSnapshot->aspectSnapshots()->createMany([
        ['aspect' => 'overall', 'average' => 4.0, 'count' => 70, 'star_1' => 10, 'star_2' => 10, 'star_3' => 15, 'star_4' => 20, 'star_5' => 15],
        ['aspect' => 'story', 'average' => 4.0, 'count' => 65, 'star_1' => 10, 'star_2' => 10, 'star_3' => 15, 'star_4' => 20, 'star_5' => 10],
        ['aspect' => 'performance', 'average' => 4.0, 'count' => 60, 'star_1' => 10, 'star_2' => 10, 'star_3' => 10, 'star_4' => 20, 'star_5' => 10],
    ]);

    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 100,
        overallAverage: 4.5, overallCount: 80,
        overallStar1: 5, overallStar2: 10, overallStar3: 15, overallStar4: 20, overallStar5: 30,
        storyAverage: 4.3, storyCount: 75,
        storyStar1: 8, storyStar2: 12, storyStar3: 18, storyStar4: 22, storyStar5: 25,
        performanceAverage: 4.7, performanceCount: 70,
        performanceStar1: 2, performanceStar2: 5, performanceStar3: 10, performanceStar4: 18, performanceStar5: 35,
        isZeroed: false,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new RatingsSyncService($catalog);
    $result = $service->syncOne($this->audiobook);

    expect($result)->toBe('snapshot_created');
    expect($this->audiobook->ratingSnapshots)->toHaveCount(2);
});

it('does not create a snapshot when ratings are unchanged', function () {
    $existingSnapshot = $this->audiobook->ratingSnapshots()->create([
        'recorded_at' => now()->subDay(),
        'num_reviews' => 100,
    ]);

    $existingSnapshot->aspectSnapshots()->createMany([
        ['aspect' => 'overall', 'average' => 4.5, 'count' => 80, 'star_1' => 5, 'star_2' => 10, 'star_3' => 15, 'star_4' => 20, 'star_5' => 30],
        ['aspect' => 'story', 'average' => 4.3, 'count' => 75, 'star_1' => 8, 'star_2' => 12, 'star_3' => 18, 'star_4' => 22, 'star_5' => 25],
        ['aspect' => 'performance', 'average' => 4.7, 'count' => 70, 'star_1' => 2, 'star_2' => 5, 'star_3' => 10, 'star_4' => 18, 'star_5' => 35],
    ]);

    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 100,
        overallAverage: 4.5, overallCount: 80,
        overallStar1: 5, overallStar2: 10, overallStar3: 15, overallStar4: 20, overallStar5: 30,
        storyAverage: 4.3, storyCount: 75,
        storyStar1: 8, storyStar2: 12, storyStar3: 18, storyStar4: 22, storyStar5: 25,
        performanceAverage: 4.7, performanceCount: 70,
        performanceStar1: 2, performanceStar2: 5, performanceStar3: 10, performanceStar4: 18, performanceStar5: 35,
        isZeroed: false,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new RatingsSyncService($catalog);
    $result = $service->syncOne($this->audiobook);

    expect($result)->toBe('unchanged');
    expect($this->audiobook->ratingSnapshots)->toHaveCount(1);
});

it('skips snapshot and increments zeroed counter on zeroed response', function () {
    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 0,
        overallAverage: 0.0, overallCount: 0,
        overallStar1: 0, overallStar2: 0, overallStar3: 0, overallStar4: 0, overallStar5: 0,
        storyAverage: 0.0, storyCount: 0,
        storyStar1: 0, storyStar2: 0, storyStar3: 0, storyStar4: 0, storyStar5: 0,
        performanceAverage: 0.0, performanceCount: 0,
        performanceStar1: 0, performanceStar2: 0, performanceStar3: 0, performanceStar4: 0, performanceStar5: 0,
        isZeroed: true,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new RatingsSyncService($catalog);
    $result = $service->syncOne($this->audiobook);

    expect($result)->toBe('zeroed_skipped');
    expect($this->audiobook->ratingSnapshots)->toHaveCount(0);

    $this->audiobook->refresh();
    expect($this->audiobook->rating_zeroed_count)->toBe(1);
});

it('resets zeroed counter on valid response after a zeroed streak', function () {
    $this->audiobook->rating_zeroed_count = 3;
    $this->audiobook->save();

    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 50,
        overallAverage: 4.5, overallCount: 40,
        overallStar1: 2, overallStar2: 5, overallStar3: 8, overallStar4: 10, overallStar5: 15,
        storyAverage: 4.3, storyCount: 35,
        storyStar1: 3, storyStar2: 4, storyStar3: 7, storyStar4: 10, storyStar5: 11,
        performanceAverage: 4.7, performanceCount: 30,
        performanceStar1: 1, performanceStar2: 3, performanceStar3: 5, performanceStar4: 10, performanceStar5: 11,
        isZeroed: false,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new RatingsSyncService($catalog);
    $result = $service->syncOne($this->audiobook);

    expect($result)->toBe('snapshot_created');

    $this->audiobook->refresh();
    expect($this->audiobook->rating_zeroed_count)->toBe(0);
});

it('sets reviews_pending when num_reviews increased even if ratings unchanged', function () {
    $existingSnapshot = $this->audiobook->ratingSnapshots()->create([
        'recorded_at' => now()->subDay(),
        'num_reviews' => 50,
    ]);

    $existingSnapshot->aspectSnapshots()->createMany([
        ['aspect' => 'overall', 'average' => 4.5, 'count' => 40, 'star_1' => 2, 'star_2' => 5, 'star_3' => 8, 'star_4' => 10, 'star_5' => 15],
        ['aspect' => 'story', 'average' => 4.3, 'count' => 35, 'star_1' => 3, 'star_2' => 4, 'star_3' => 7, 'star_4' => 10, 'star_5' => 11],
        ['aspect' => 'performance', 'average' => 4.7, 'count' => 30, 'star_1' => 1, 'star_2' => 3, 'star_3' => 5, 'star_4' => 10, 'star_5' => 11],
    ]);

    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 100,
        overallAverage: 4.5, overallCount: 40,
        overallStar1: 2, overallStar2: 5, overallStar3: 8, overallStar4: 10, overallStar5: 15,
        storyAverage: 4.3, storyCount: 35,
        storyStar1: 3, storyStar2: 4, storyStar3: 7, storyStar4: 10, storyStar5: 11,
        performanceAverage: 4.7, performanceCount: 30,
        performanceStar1: 1, performanceStar2: 3, performanceStar3: 5, performanceStar4: 10, performanceStar5: 11,
        isZeroed: false,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new RatingsSyncService($catalog);
    $result = $service->syncOne($this->audiobook);

    // Ratings unchanged, but review count rose — flag set, no snapshot.
    expect($result)->toBe('unchanged');
    expect($this->audiobook->ratingSnapshots)->toHaveCount(1);

    $this->audiobook->refresh();
    expect($this->audiobook->reviews_pending)->toBeTrue();
});

it('does not set reviews_pending when num_reviews did not increase', function () {
    $existingSnapshot = $this->audiobook->ratingSnapshots()->create([
        'recorded_at' => now()->subDay(),
        'num_reviews' => 100,
    ]);

    $existingSnapshot->aspectSnapshots()->createMany([
        ['aspect' => 'overall', 'average' => 4.5, 'count' => 40, 'star_1' => 2, 'star_2' => 5, 'star_3' => 8, 'star_4' => 10, 'star_5' => 15],
        ['aspect' => 'story', 'average' => 4.3, 'count' => 35, 'star_1' => 3, 'star_2' => 4, 'star_3' => 7, 'star_4' => 10, 'star_5' => 11],
        ['aspect' => 'performance', 'average' => 4.7, 'count' => 30, 'star_1' => 1, 'star_2' => 3, 'star_3' => 5, 'star_4' => 10, 'star_5' => 11],
    ]);

    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 100,
        overallAverage: 4.5, overallCount: 40,
        overallStar1: 2, overallStar2: 5, overallStar3: 8, overallStar4: 10, overallStar5: 15,
        storyAverage: 4.3, storyCount: 35,
        storyStar1: 3, storyStar2: 4, storyStar3: 7, storyStar4: 10, storyStar5: 11,
        performanceAverage: 4.7, performanceCount: 30,
        performanceStar1: 1, performanceStar2: 3, performanceStar3: 5, performanceStar4: 10, performanceStar5: 11,
        isZeroed: false,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new RatingsSyncService($catalog);
    $result = $service->syncOne($this->audiobook);

    expect($result)->toBe('unchanged');

    $this->audiobook->refresh();
    expect($this->audiobook->reviews_pending)->toBeFalse();
});

it('returns no_data when API returns empty results', function () {
    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([]);

    $service = new RatingsSyncService($catalog);
    $result = $service->syncOne($this->audiobook);

    expect($result)->toBe('no_data');
    expect($this->audiobook->ratingSnapshots)->toHaveCount(0);
});
