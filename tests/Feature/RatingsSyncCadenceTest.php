<?php

use App\Models\Audiobook;
use App\Services\RatingsSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses()->group('feature');

/**
 * Cadence behind next_check_at — see docs/spec/05-adr-ratings-sync-cadence.md.
 */
function makeService(): RatingsSyncService
{
    return app(RatingsSyncService::class);
}

function audiobookWithSnapshotAgedDays(?int $daysSinceChange, string $publishedAt = '-2 years'): Audiobook
{
    $audiobook = Audiobook::factory()->create([
        'published_at' => Carbon::parse($publishedAt),
    ]);

    if ($daysSinceChange !== null) {
        $audiobook->ratingSnapshots()->create([
            'recorded_at' => now()->subDays($daysSinceChange),
            'num_reviews' => 10,
        ]);
    }

    return $audiobook;
}

it('keeps recently published titles at the daily floor regardless of change age', function () {
    // Ratings unchanged for 200 days, but published within the newness window.
    $audiobook = audiobookWithSnapshotAgedDays(200, publishedAt: '-10 days');

    expect(makeService()->baseCheckIntervalDays($audiobook))->toBe(1);
});

it('checks recently changed titles daily', function () {
    $audiobook = audiobookWithSnapshotAgedDays(5);

    expect(makeService()->baseCheckIntervalDays($audiobook))->toBe(1);
});

it('backs off across the bands as ratings go quiet', function (int $daysSinceChange, int $expected) {
    $audiobook = audiobookWithSnapshotAgedDays($daysSinceChange);

    expect(makeService()->baseCheckIntervalDays($audiobook))->toBe($expected);
})->with([
    'just under 30d' => [29, 1],
    '30d band' => [45, 3],
    '60d band' => [75, 4],
    '120d cold cap' => [200, 5],
]);

it('drifts a never-rated title past the newness window to the cold cap', function () {
    // Published 2 years ago, never gained a single rating snapshot.
    $audiobook = audiobookWithSnapshotAgedDays(null);

    expect(makeService()->baseCheckIntervalDays($audiobook))->toBe(5);
});

it('keeps a never-rated but recently published title at the daily floor', function () {
    $audiobook = audiobookWithSnapshotAgedDays(null, publishedAt: '-10 days');

    expect(makeService()->baseCheckIntervalDays($audiobook))->toBe(1);
});

it('stamps next_check_at within the jittered daily window after a sync', function () {
    $audiobook = Audiobook::factory()->create([
        'asin' => 'B07CADENCE',
        'region' => 'US',
        'published_at' => now()->subYears(2),
        'next_check_at' => null,
    ]);

    Http::fake([
        'api.audible.com/*' => Http::response([
            'products' => [[
                'asin' => 'B07CADENCE',
                'rating' => [
                    'num_reviews' => 10,
                    'overall_distribution' => ['average_rating' => 4.0, 'num_ratings' => 8, 'num_one_star_ratings' => 1, 'num_two_star_ratings' => 1, 'num_three_star_ratings' => 1, 'num_four_star_ratings' => 2, 'num_five_star_ratings' => 3],
                    'story_distribution' => ['average_rating' => 4.0, 'num_ratings' => 8, 'num_one_star_ratings' => 1, 'num_two_star_ratings' => 1, 'num_three_star_ratings' => 1, 'num_four_star_ratings' => 2, 'num_five_star_ratings' => 3],
                    'performance_distribution' => ['average_rating' => 4.0, 'num_ratings' => 8, 'num_one_star_ratings' => 1, 'num_two_star_ratings' => 1, 'num_three_star_ratings' => 1, 'num_four_star_ratings' => 2, 'num_five_star_ratings' => 3],
                ],
            ]],
        ]),
    ]);

    makeService()->syncOne($audiobook);
    $audiobook->refresh();

    // Fresh snapshot => 0 days since change => 1-day floor, ±15% jitter on 24h.
    expect($audiobook->next_check_at)->not->toBeNull()
        ->and($audiobook->next_check_at->greaterThan(now()->addHours(19)))->toBeTrue()
        ->and($audiobook->next_check_at->lessThan(now()->addHours(29)))->toBeTrue();
});

it('selects only due, published titles ordered with nulls first', function () {
    $neverChecked = Audiobook::factory()->create(['next_check_at' => null, 'published_at' => now()->subYear()]);
    $overdue = Audiobook::factory()->create(['next_check_at' => now()->subDay(), 'published_at' => now()->subYear()]);
    $notYetDue = Audiobook::factory()->create(['next_check_at' => now()->addDay(), 'published_at' => now()->subYear()]);
    $preOrder = Audiobook::factory()->create(['next_check_at' => null, 'published_at' => now()->addMonth()]);

    $due = Audiobook::dueForRatingsSync()->get();

    expect($due->pluck('id')->all())->toBe([$neverChecked->id, $overdue->id])
        ->and($due->contains($notYetDue))->toBeFalse()
        ->and($due->contains($preOrder))->toBeFalse();
});

it('respects the batch limit', function () {
    Audiobook::factory()->count(5)->create(['next_check_at' => null, 'published_at' => now()->subYear()]);

    expect(Audiobook::dueForRatingsSync(3)->get())->toHaveCount(3);
});
