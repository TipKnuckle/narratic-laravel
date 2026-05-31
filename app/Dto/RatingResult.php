<?php

namespace App\Dto;

class RatingResult
{
    public function __construct(
        public readonly string $asin,
        public readonly int $numReviews,
        public readonly float $overallAverage,
        public readonly int $overallCount,
        public readonly int $overallStar1,
        public readonly int $overallStar2,
        public readonly int $overallStar3,
        public readonly int $overallStar4,
        public readonly int $overallStar5,
        public readonly float $storyAverage,
        public readonly int $storyCount,
        public readonly int $storyStar1,
        public readonly int $storyStar2,
        public readonly int $storyStar3,
        public readonly int $storyStar4,
        public readonly int $storyStar5,
        public readonly float $performanceAverage,
        public readonly int $performanceCount,
        public readonly int $performanceStar1,
        public readonly int $performanceStar2,
        public readonly int $performanceStar3,
        public readonly int $performanceStar4,
        public readonly int $performanceStar5,
        public readonly bool $isZeroed,
    ) {}

    public static function fromApiResponse(array $data, string $asin): self
    {
        $overall = $data['overall_distribution'] ?? [];
        $story = $data['story_distribution'] ?? [];
        $performance = $data['performance_distribution'] ?? [];

        $overallCount = (int) ($overall['num_ratings'] ?? 0);
        $storyCount = (int) ($story['num_ratings'] ?? 0);
        $performanceCount = (int) ($performance['num_ratings'] ?? 0);

        return new self(
            asin: $asin,
            numReviews: (int) ($data['num_reviews'] ?? 0),
            overallAverage: (float) ($overall['average_rating'] ?? 0),
            overallCount: $overallCount,
            overallStar1: (int) ($overall['num_one_star_ratings'] ?? 0),
            overallStar2: (int) ($overall['num_two_star_ratings'] ?? 0),
            overallStar3: (int) ($overall['num_three_star_ratings'] ?? 0),
            overallStar4: (int) ($overall['num_four_star_ratings'] ?? 0),
            overallStar5: (int) ($overall['num_five_star_ratings'] ?? 0),
            storyAverage: (float) ($story['average_rating'] ?? 0),
            storyCount: $storyCount,
            storyStar1: (int) ($story['num_one_star_ratings'] ?? 0),
            storyStar2: (int) ($story['num_two_star_ratings'] ?? 0),
            storyStar3: (int) ($story['num_three_star_ratings'] ?? 0),
            storyStar4: (int) ($story['num_four_star_ratings'] ?? 0),
            storyStar5: (int) ($story['num_five_star_ratings'] ?? 0),
            performanceAverage: (float) ($performance['average_rating'] ?? 0),
            performanceCount: $performanceCount,
            performanceStar1: (int) ($performance['num_one_star_ratings'] ?? 0),
            performanceStar2: (int) ($performance['num_two_star_ratings'] ?? 0),
            performanceStar3: (int) ($performance['num_three_star_ratings'] ?? 0),
            performanceStar4: (int) ($performance['num_four_star_ratings'] ?? 0),
            performanceStar5: (int) ($performance['num_five_star_ratings'] ?? 0),
            isZeroed: $overallCount === 0 && $storyCount === 0 && $performanceCount === 0,
        );
    }
}
