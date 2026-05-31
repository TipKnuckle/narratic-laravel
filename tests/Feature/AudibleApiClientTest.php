<?php

use App\Enums\Region;
use App\Enums\SearchType;
use App\Services\AudibleApiClient;
use Illuminate\Support\Facades\Http;

it('performs a search and returns product results', function () {
    Http::fake([
        'api.audible.com/*' => Http::response([
            'products' => [
                [
                    'asin' => 'B07DXYZ123',
                    'title' => 'The Fifth Season',
                    'subtitle' => null,
                    'publisher_summary' => 'A stunning novel...',
                    'runtime_length_min' => 630,
                    'release_date' => '2018-05-08T00:00:00.000Z',
                    'authors' => [['name' => 'N.K. Jemisin']],
                    'narrators' => [['name' => 'Gabriel Le Dit']],
                    'product_images' => [500 => 'https://example.com/cover.jpg'],
                ],
            ],
            'total_results' => 1,
            'page' => 0,
            'num_results' => 1,
        ]),
    ]);

    $client = app(AudibleApiClient::class);
    $results = $client->search(SearchType::Narrator, 'Gabriel Le Dit', Region::US);

    expect($results)->toHaveCount(1);
    expect($results[0]->asin)->toBe('B07DXYZ123');
    expect($results[0]->authors)->toBe(['N.K. Jemisin']);
});

it('fetches ratings in batches', function () {
    Http::fake([
        'api.audible.com/*' => Http::response([
            'products' => [
                [
                    'asin' => 'B07DXYZ123',
                    'rating' => [
                        'num_reviews' => 12847,
                        'overall_distribution' => [
                            'average_rating' => 4.5,
                            'num_ratings' => 8234,
                            'num_one_star_ratings' => 120,
                            'num_two_star_ratings' => 245,
                            'num_three_star_ratings' => 890,
                            'num_four_star_ratings' => 2340,
                            'num_five_star_ratings' => 4639,
                        ],
                        'story_distribution' => [
                            'average_rating' => 4.4,
                            'num_ratings' => 7100,
                            'num_one_star_ratings' => 150,
                            'num_two_star_ratings' => 280,
                            'num_three_star_ratings' => 950,
                            'num_four_star_ratings' => 2100,
                            'num_five_star_ratings' => 4620,
                        ],
                        'performance_distribution' => [
                            'average_rating' => 4.7,
                            'num_ratings' => 7800,
                            'num_one_star_ratings' => 80,
                            'num_two_star_ratings' => 150,
                            'num_three_star_ratings' => 400,
                            'num_four_star_ratings' => 1900,
                            'num_five_star_ratings' => 5270,
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $client = app(AudibleApiClient::class);
    $results = $client->fetchRatings(['B07DXYZ123'], Region::US);

    expect($results)->toHaveCount(1);
    expect($results[0]->asin)->toBe('B07DXYZ123');
    expect($results[0]->numReviews)->toBe(12847);
    expect($results[0]->isZeroed)->toBeFalse();
});

it('routes requests to the correct regional domain', function () {
    Http::fake([
        'api.audible.co.uk/*' => Http::response([
            'products' => [
                ['asin' => 'UKASIN001', 'title' => 'UK Book', 'publisher_summary' => '', 'authors' => [], 'narrators' => []],
            ],
        ]),
    ]);

    $client = app(AudibleApiClient::class);
    $results = $client->search(SearchType::Title, 'test', Region::UK);

    expect($results)->toHaveCount(1);
    expect($results[0]->asin)->toBe('UKASIN001');
});

it('fetches reviews', function () {
    Http::fake([
        'api.audible.com/*' => Http::response([
            'customer_reviews' => [
                [
                    'id' => 'MR3ABC123',
                    'title' => 'Great narration',
                    'submission_date' => '2024-03-15T14:32:00.000Z',
                    'author_name' => 'Jane M.',
                    'format' => 'Freeform',
                    'body' => 'This audiobook kept me on the edge of my seat.',
                    'ratings' => [
                        'overall_rating' => 5,
                        'story_rating' => 5,
                        'performance_rating' => 5,
                    ],
                ],
            ],
        ]),
    ]);

    $client = app(AudibleApiClient::class);
    $results = $client->fetchReviews('B07DXYZ123', Region::US);

    expect($results)->toHaveCount(1);
    expect($results[0]->externalId)->toBe('MR3ABC123');
    expect($results[0]->format)->toBe('freeform');
    expect($results[0]->ratingOverall)->toBe(5);
});
