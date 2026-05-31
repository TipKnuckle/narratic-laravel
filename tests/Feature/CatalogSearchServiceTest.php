<?php

use App\Contracts\AudibleCatalog;
use App\Dto\ProductResult;
use App\Enums\Region;
use App\Enums\SearchType;
use App\Models\Audiobook;
use App\Models\Contributor;
use App\Services\CatalogSearchService;
use Illuminate\Support\Facades\App;

it('ingests audiobooks from search results', function () {
    $product = ProductResult::fromApiResponse([
        'asin' => 'B07DXYZ123',
        'title' => 'The Fifth Season',
        'subtitle' => null,
        'publisher_summary' => 'A stunning novel...',
        'runtime_length_min' => 630,
        'release_date' => '2018-05-08T00:00:00.000Z',
        'authors' => [['name' => 'N.K. Jemisin']],
        'narrators' => [['name' => 'Gabriel Le Dit']],
        'product_images' => [500 => 'https://example.com/cover.jpg'],
    ], Region::US);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')
        ->once()
        ->with(SearchType::Narrator, 'Gabriel Le Dit', Region::US)
        ->andReturn([$product]);

    App::instance(AudibleCatalog::class, $catalog);

    $service = app(CatalogSearchService::class);
    $audiobooks = $service->searchAndIngest(SearchType::Narrator, 'Gabriel Le Dit', Region::US);

    expect($audiobooks)->toHaveCount(1);
    expect($audiobooks[0]->asin)->toBe('B07DXYZ123');
    expect($audiobooks[0]->title)->toBe('The Fifth Season');
    expect($audiobooks[0]->region)->toBe('US');

    $this->assertDatabaseHas((new Audiobook)->getTable(), [
        'asin' => 'B07DXYZ123',
        'region' => 'US',
        'title' => 'The Fifth Season',
    ]);
});

it('syncs contributors during ingestion', function () {
    $product = ProductResult::fromApiResponse([
        'asin' => 'B07TEST999',
        'title' => 'Test Book',
        'subtitle' => null,
        'publisher_summary' => '',
        'runtime_length_min' => null,
        'release_date' => null,
        'authors' => [['name' => 'N.K. Jemisin'], ['name' => 'Another Author']],
        'narrators' => [['name' => 'Gabriel Le Dit']],
        'product_images' => [],
    ], Region::US);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')
        ->once()
        ->andReturn([$product]);

    App::instance(AudibleCatalog::class, $catalog);

    $service = app(CatalogSearchService::class);
    $service->searchAndIngest(SearchType::Title, 'test', Region::US);

    expect(Contributor::where('slug', 'nk-jemisin')->exists())->toBeTrue();
    expect(Contributor::where('slug', 'gabriel-le-dit')->exists())->toBeTrue();

    $audiobook = Audiobook::where('asin', 'B07TEST999')->first();
    expect($audiobook->contributors->count())->toBe(3);

    $authorRoles = $audiobook->contributors->where('pivot.role', 'author');
    expect($authorRoles->count())->toBe(2);

    $narratorRoles = $audiobook->contributors->where('pivot.role', 'narrator');
    expect($narratorRoles->count())->toBe(1);
});

it('upserts existing audiobooks instead of duplicating', function () {
    Audiobook::factory()->create([
        'asin' => 'B07EXISTING',
        'region' => 'US',
        'title' => 'Old Title',
    ]);

    $product = ProductResult::fromApiResponse([
        'asin' => 'B07EXISTING',
        'title' => 'Updated Title',
        'subtitle' => null,
        'publisher_summary' => 'Updated description',
        'runtime_length_min' => null,
        'release_date' => null,
        'authors' => [],
        'narrators' => [],
        'product_images' => [],
    ], Region::US);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')
        ->once()
        ->andReturn([$product]);

    App::instance(AudibleCatalog::class, $catalog);

    $service = app(CatalogSearchService::class);
    $audiobooks = $service->searchAndIngest(SearchType::Title, 'test', Region::US);

    expect($audiobooks)->toHaveCount(1);
    expect($audiobooks[0]->title)->toBe('Updated Title');
    expect(Audiobook::where('asin', 'B07EXISTING')->count())->toBe(1);
});
