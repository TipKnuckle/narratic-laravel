<?php

use App\Dto\ProductResult;
use App\Enums\Region;

it('parses a full product response', function () {
    $data = [
        'asin' => 'B07DXYZ123',
        'title' => 'The Fifth Season',
        'subtitle' => null,
        'publisher_summary' => 'A stunning debut novel...',
        'runtime_length_min' => 630,
        'release_date' => '2018-05-08T00:00:00.000Z',
        'authors' => [['name' => 'N.K. Jemisin']],
        'narrators' => [['name' => 'Gabriel Le Dit']],
        'product_images' => [500 => 'https://example.com/cover.jpg'],
    ];

    $result = ProductResult::fromApiResponse($data, Region::US);

    expect($result->asin)->toBe('B07DXYZ123');
    expect($result->title)->toBe('The Fifth Season');
    expect($result->subtitle)->toBeNull();
    expect($result->description)->toBe('A stunning debut novel...');
    expect($result->runtimeMinutes)->toBe(630);
    expect($result->coverImageUrl)->toBe('https://example.com/cover.jpg');
    expect($result->releaseDate)->toBe('2018-05-08T00:00:00.000Z');
    expect($result->region)->toBe(Region::US);
    expect($result->authors)->toBe(['N.K. Jemisin']);
    expect($result->narrators)->toBe(['Gabriel Le Dit']);
    expect($result->rating)->toBeNull();
});

it('parses rating from product response', function () {
    $data = [
        'asin' => 'B07DXYZ123',
        'title' => 'Test Book',
        'publisher_summary' => '',
        'authors' => [],
        'narrators' => [],
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
    ];

    $result = ProductResult::fromApiResponse($data, Region::US);

    expect($result->rating)->not->toBeNull();
    expect($result->rating->asin)->toBe('B07DXYZ123');
    expect($result->rating->numReviews)->toBe(12847);
    expect($result->rating->overallAverage)->toBe(4.5);
    expect($result->rating->overallCount)->toBe(8234);
    expect($result->rating->isZeroed)->toBeFalse();
});

it('detects zeroed ratings', function () {
    $data = [
        'asin' => 'B07DXYZ123',
        'title' => 'Test',
        'publisher_summary' => '',
        'authors' => [],
        'narrators' => [],
        'rating' => [
            'num_reviews' => 0,
            'overall_distribution' => [
                'average_rating' => 0,
                'num_ratings' => 0,
                'num_one_star_ratings' => 0,
                'num_two_star_ratings' => 0,
                'num_three_star_ratings' => 0,
                'num_four_star_ratings' => 0,
                'num_five_star_ratings' => 0,
            ],
            'story_distribution' => [
                'average_rating' => 0,
                'num_ratings' => 0,
                'num_one_star_ratings' => 0,
                'num_two_star_ratings' => 0,
                'num_three_star_ratings' => 0,
                'num_four_star_ratings' => 0,
                'num_five_star_ratings' => 0,
            ],
            'performance_distribution' => [
                'average_rating' => 0,
                'num_ratings' => 0,
                'num_one_star_ratings' => 0,
                'num_two_star_ratings' => 0,
                'num_three_star_ratings' => 0,
                'num_four_star_ratings' => 0,
                'num_five_star_ratings' => 0,
            ],
        ],
    ];

    $result = ProductResult::fromApiResponse($data, Region::US);

    expect($result->rating->isZeroed)->toBeTrue();
});
