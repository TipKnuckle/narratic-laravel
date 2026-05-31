<?php

use App\Models\Audiobook;
use Illuminate\Support\Facades\Http;

uses()->group('feature');

it('syncs ratings for all due audiobooks', function () {
    $audiobook = Audiobook::factory()->create([
        'asin' => 'B07DXYZ123',
        'region' => 'US',
        'ratings_synced_at' => null,
        'published_at' => now()->subYear(),
    ]);

    Http::fake([
        'api.audible.com/*' => Http::response([
            'products' => [
                [
                    'asin' => 'B07DXYZ123',
                    'rating' => [
                        'num_reviews' => 100,
                        'overall_distribution' => [
                            'average_rating' => 4.5,
                            'num_ratings' => 80,
                            'num_one_star_ratings' => 5,
                            'num_two_star_ratings' => 10,
                            'num_three_star_ratings' => 15,
                            'num_four_star_ratings' => 20,
                            'num_five_star_ratings' => 30,
                        ],
                        'story_distribution' => [
                            'average_rating' => 4.3,
                            'num_ratings' => 75,
                            'num_one_star_ratings' => 8,
                            'num_two_star_ratings' => 12,
                            'num_three_star_ratings' => 18,
                            'num_four_star_ratings' => 22,
                            'num_five_star_ratings' => 25,
                        ],
                        'performance_distribution' => [
                            'average_rating' => 4.7,
                            'num_ratings' => 70,
                            'num_one_star_ratings' => 2,
                            'num_two_star_ratings' => 5,
                            'num_three_star_ratings' => 10,
                            'num_four_star_ratings' => 18,
                            'num_five_star_ratings' => 35,
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('audiobook:sync-ratings')
        ->expectsOutputToContain('snapshot_created')
        ->assertSuccessful();

    $audiobook->refresh();
    expect($audiobook->ratingSnapshots)->toHaveCount(1);
    expect($audiobook->ratings_synced_at)->not->toBeNull();
});

it('reports no work when no audiobooks are due', function () {
    Audiobook::factory()->create([
        'ratings_synced_at' => now()->subMinute(),
        'next_check_at' => now()->addDay(),
        'published_at' => now()->subYear(),
    ]);

    $this->artisan('audiobook:sync-ratings')
        ->expectsOutput('No audiobooks due for a ratings sync.')
        ->assertSuccessful();
});

it('syncs a single audiobook by asin and region', function () {
    Audiobook::factory()->create([
        'asin' => 'B07TEST001',
        'region' => 'US',
        'ratings_synced_at' => null,
        'published_at' => now()->subYear(),
    ]);

    Http::fake([
        'api.audible.com/*' => Http::response([
            'products' => [
                [
                    'asin' => 'B07TEST001',
                    'rating' => [
                        'num_reviews' => 10,
                        'overall_distribution' => [
                            'average_rating' => 4.0,
                            'num_ratings' => 8,
                            'num_one_star_ratings' => 1,
                            'num_two_star_ratings' => 1,
                            'num_three_star_ratings' => 1,
                            'num_four_star_ratings' => 2,
                            'num_five_star_ratings' => 3,
                        ],
                        'story_distribution' => [
                            'average_rating' => 4.0,
                            'num_ratings' => 8,
                            'num_one_star_ratings' => 1,
                            'num_two_star_ratings' => 1,
                            'num_three_star_ratings' => 1,
                            'num_four_star_ratings' => 2,
                            'num_five_star_ratings' => 3,
                        ],
                        'performance_distribution' => [
                            'average_rating' => 4.0,
                            'num_ratings' => 8,
                            'num_one_star_ratings' => 1,
                            'num_two_star_ratings' => 1,
                            'num_three_star_ratings' => 1,
                            'num_four_star_ratings' => 2,
                            'num_five_star_ratings' => 3,
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('audiobook:sync-ratings --asin=B07TEST001 --region=US')
        ->expectsOutputToContain('snapshot_created')
        ->assertSuccessful();
});

it('warns when single asin is not found in the database', function () {
    $this->artisan('audiobook:sync-ratings --asin=NONEXISTENT --region=US')
        ->expectsOutputToContain('not found')
        ->assertSuccessful();
});
