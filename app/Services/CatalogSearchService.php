<?php

namespace App\Services;

use App\Contracts\AudibleCatalog;
use App\Dto\ProductResult;
use App\Enums\Region;
use App\Enums\SearchType;
use App\Models\Audiobook;

class CatalogSearchService
{
    public function __construct(
        private readonly AudibleCatalog $catalog,
        private readonly ContributorService $contributorService,
    ) {}

    /**
     * Search Audible and ingest results into the database.
     *
     * @return Audiobook[] created/updated
     */
    public function searchAndIngest(SearchType $type, string $term, Region $region): array
    {
        $results = $this->catalog->search($type, $term, $region);
        $audiobooks = [];

        foreach ($results as $product) {
            $audiobook = $this->upsertAudiobook($product);
            $this->syncContributors($audiobook, $product->authors, $product->narrators);
            $audiobooks[] = $audiobook;
        }

        return $audiobooks;
    }

    private function upsertAudiobook(ProductResult $product): Audiobook
    {
        return Audiobook::updateOrCreate(
            ['asin' => $product->asin, 'region' => $product->region->value],
            [
                'title' => $product->title,
                'subtitle' => $product->subtitle,
                'description' => $product->description,
                'runtime_minutes' => $product->runtimeMinutes,
                'cover_image_url' => $product->coverImageUrl,
                'published_at' => $product->releaseDate,
            ],
        );
    }

    private function syncContributors(Audiobook $audiobook, array $authorNames, array $narratorNames): void
    {
        $syncData = [];

        foreach ($authorNames as $name) {
            $contributor = $this->contributorService->findOrCreate($name);
            $syncData[$contributor->id] = ['role' => 'author'];
        }

        foreach ($narratorNames as $name) {
            $contributor = $this->contributorService->findOrCreate($name);
            $syncData[$contributor->id] = ['role' => 'narrator'];
        }

        $audiobook->contributors()->sync($syncData);
    }
}
