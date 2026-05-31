<?php

namespace App\Services;

use App\Contracts\AudibleCatalog;
use App\Dto\ProductResult;
use App\Dto\RatingResult;
use App\Dto\ReviewResult;
use App\Enums\Region;
use App\Enums\SearchType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class AudibleApiClient implements AudibleCatalog
{
    private const MAX_BATCH_SIZE = 50;

    private const TIMEOUT_SEARCH = 30;

    private const TIMEOUT_BATCH = 90;

    private const BATCH_INTERVAL_SECONDS = 1.0;

    public function search(SearchType $type, string $term, Region $region, int $page = 0): array
    {
        $response = $this->client(self::TIMEOUT_SEARCH)
            ->get("https://{$region->baseDomain()}/1.0/catalog/products", [
                'response_groups' => 'product_desc,contributors,rating,product_attrs,product_extended_attrs,media',
                'products_sort_by' => $type->sortBy(),
                $type->queryParameter() => $term,
                'page' => $page,
                'num_results' => 50,
                'image_sizes' => '100,500',
            ]);

        $response->throw();

        $body = $response->json();

        return array_map(
            fn (array $product) => ProductResult::fromApiResponse($product, $region),
            $body['products'] ?? [],
        );
    }

    public function fetchRatings(array $asins, Region $region): array
    {
        $results = [];
        $chunks = array_chunk($asins, self::MAX_BATCH_SIZE);
        $totalChunks = count($chunks);

        foreach ($chunks as $index => $chunk) {
            $response = $this->client(self::TIMEOUT_BATCH)
                ->get("https://{$region->baseDomain()}/1.0/catalog/products", [
                    'response_groups' => 'rating',
                    'asins' => implode(',', $chunk),
                ]);

            $response->throw();

            $body = $response->json();

            foreach ($body['products'] ?? [] as $product) {
                if (isset($product['rating'])) {
                    $results[] = RatingResult::fromApiResponse($product['rating'], $product['asin']);
                }
            }

            // Throttle between batches
            if ($index < $totalChunks - 1) {
                usleep((int) (self::BATCH_INTERVAL_SECONDS * 1_000_000));
            }
        }

        return $results;
    }

    public function fetchReviews(string $asin, Region $region, int $page = 0): array
    {
        $response = $this->client(self::TIMEOUT_BATCH)
            ->get("https://{$region->baseDomain()}/1.0/catalog/products/{$asin}/reviews/", [
                'sort_by' => 'MostRecent',
                'num_results' => 15,
                'page' => $page,
            ]);

        $response->throw();

        $body = $response->json();

        return array_map(
            fn (array $review) => ReviewResult::fromApiResponse($review),
            $body['customer_reviews'] ?? [],
        );
    }

    private function client(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->retry(2, 500, fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()));
    }
}
