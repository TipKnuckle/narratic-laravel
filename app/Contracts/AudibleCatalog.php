<?php

namespace App\Contracts;

use App\Dto\ProductResult;
use App\Dto\RatingResult;
use App\Dto\ReviewResult;
use App\Enums\Region;
use App\Enums\SearchType;

interface AudibleCatalog
{
    /** @return ProductResult[] */
    public function search(SearchType $type, string $term, Region $region, int $page = 0): array;

    /**
     * @param  string[]  $asins
     * @return RatingResult[]
     */
    public function fetchRatings(array $asins, Region $region): array;

    /** @return ReviewResult[] */
    public function fetchReviews(string $asin, Region $region, int $page = 0): array;
}
