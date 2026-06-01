<?php

namespace App\Dto;

use App\Models\AvailabilityEvent;
use App\Models\RatingSnapshot;
use App\Models\Review;
use App\Models\Tracking;

class DigestContent
{
    /**
     * @param  Review[]  $newReviews
     * @param  array<int, array{previous: RatingSnapshot, current: RatingSnapshot}>  $ratingChanges
     * @param  AvailabilityEvent[]  $availabilityChanges
     * @param  Tracking[]  $newReleases
     */
    public function __construct(
        public readonly array $newReviews,
        public readonly array $ratingChanges,
        public readonly array $availabilityChanges,
        public readonly array $newReleases,
    ) {}

    public function isEmpty(): bool
    {
        return empty($this->newReviews)
            && empty($this->ratingChanges)
            && empty($this->availabilityChanges)
            && empty($this->newReleases);
    }
}
