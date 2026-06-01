<?php

use App\Contracts\AudibleCatalog;
use App\Contracts\Membership;
use App\Dto\ProductResult;
use App\Enums\Region;
use App\Enums\SearchType;
use App\Events\NewReleaseAutoTracked;
use App\Models\Audiobook;
use App\Models\AutotrackRule;
use App\Models\Tracking;
use App\Models\User;
use App\Services\AutotrackingService;
use App\Services\CatalogSearchService;
use App\Services\ContributorService;
use Illuminate\Support\Facades\Event;

uses()->group('feature');

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates auto trackings for new releases within window', function () {
    $rule = AutotrackRule::factory()->create([
        'user_id' => $this->user->id,
        'search_type' => SearchType::Author->value,
        'term' => 'N.K. Jemisin',
        'region' => 'US',
    ]);

    $product = ProductResult::fromApiResponse([
        'asin' => 'B07DXYZ123',
        'title' => 'The Fifth Season',
        'subtitle' => null,
        'publisher_summary' => 'A stunning novel...',
        'runtime_length_min' => 630,
        'release_date' => now()->subDay(1)->toIso8601String(),
        'authors' => [['name' => 'N.K. Jemisin']],
        'narrators' => [['name' => 'Gabriel Le Dit']],
        'product_images' => [500 => 'https://example.com/cover.jpg'],
    ], Region::US);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')
        ->with(SearchType::Author, 'N.K. Jemisin', Region::US)
        ->andReturn([$product]);

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);
    $membership->allows('maxTracked')->andReturn(PHP_INT_MAX);

    $catalogSearch = new CatalogSearchService($catalog, app(ContributorService::class));
    $service = new AutotrackingService($catalogSearch, $membership);

    Event::fake();

    $result = $service->runRule($rule);

    expect($result)->toBe(['tracked' => 1, 'skipped' => 0]);

    $audiobook = Audiobook::where('asin', 'B07DXYZ123')->first();
    expect($audiobook)->not->toBeNull();

    $tracking = Tracking::where('user_id', $this->user->id)->first();
    expect($tracking->audiobook_id)->toBe($audiobook->id);
    expect($tracking->source)->toBe('auto');

    Event::assertDispatched(NewReleaseAutoTracked::class);
});

it('skips titles outside the new-release window', function () {
    $rule = AutotrackRule::factory()->create([
        'user_id' => $this->user->id,
        'search_type' => SearchType::Author->value,
        'term' => 'Old Author',
        'region' => 'US',
    ]);

    $product = ProductResult::fromApiResponse([
        'asin' => 'B07OLD001',
        'title' => 'Old Book',
        'subtitle' => null,
        'publisher_summary' => '',
        'runtime_length_min' => null,
        'release_date' => now()->subDays(30)->toIso8601String(),
        'authors' => [['name' => 'Old Author']],
        'narrators' => [],
        'product_images' => [],
    ], Region::US);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')
        ->with(SearchType::Author, 'Old Author', Region::US)
        ->andReturn([$product]);

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);
    $membership->allows('maxTracked')->andReturn(PHP_INT_MAX);

    $catalogSearch = new CatalogSearchService($catalog, app(ContributorService::class));
    $service = new AutotrackingService($catalogSearch, $membership);

    $result = $service->runRule($rule);

    expect($result)->toBe(['tracked' => 0, 'skipped' => 1]);
    expect(Tracking::where('user_id', $this->user->id)->count())->toBe(0);
});

it('skips already-tracked titles', function () {
    $rule = AutotrackRule::factory()->create([
        'user_id' => $this->user->id,
        'search_type' => SearchType::Title->value,
        'term' => 'Duplicate Book',
        'region' => 'US',
    ]);

    $product = ProductResult::fromApiResponse([
        'asin' => 'B07DUPE001',
        'title' => 'Duplicate Book',
        'subtitle' => null,
        'publisher_summary' => '',
        'runtime_length_min' => null,
        'release_date' => now()->subDay(1)->toIso8601String(),
        'authors' => [],
        'narrators' => [],
        'product_images' => [],
    ], Region::US);

    $catalog = mock(AudibleCatalog::class);
    $catalog->allows('search')
        ->with(SearchType::Title, 'Duplicate Book', Region::US)
        ->andReturn([$product]);

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);
    $membership->allows('maxTracked')->andReturn(PHP_INT_MAX);

    $catalogSearch = new CatalogSearchService($catalog, app(ContributorService::class));
    $service = new AutotrackingService($catalogSearch, $membership);

    // Pre-create a tracking so the dedupe kicks in
    $service->runRule($rule);
    $service->runRule($rule);

    expect(Tracking::where('user_id', $this->user->id)->count())->toBe(1);
});

it('respects membership maxTracked cap', function () {
    $rule = AutotrackRule::factory()->create([
        'user_id' => $this->user->id,
        'search_type' => SearchType::Author->value,
        'term' => 'Popular Author',
        'region' => 'US',
    ]);

    $product = ProductResult::fromApiResponse([
        'asin' => 'B07CAP001',
        'title' => 'Popular Book',
        'subtitle' => null,
        'publisher_summary' => '',
        'runtime_length_min' => null,
        'release_date' => now()->subDay(1)->toIso8601String(),
        'authors' => [['name' => 'Popular Author']],
        'narrators' => [],
        'product_images' => [],
    ], Region::US);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')
        ->with(SearchType::Author, 'Popular Author', Region::US)
        ->andReturn([$product]);

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);
    $membership->allows('maxTracked')->andReturn(0);

    $catalogSearch = new CatalogSearchService($catalog, app(ContributorService::class));
    $service = new AutotrackingService($catalogSearch, $membership);

    $result = $service->runRule($rule);

    expect($result)->toBe(['tracked' => 0, 'skipped' => 1]);
    expect(Tracking::where('user_id', $this->user->id)->count())->toBe(0);
});

it('skips rule when user is not active', function () {
    $rule = AutotrackRule::factory()->create([
        'user_id' => $this->user->id,
        'search_type' => SearchType::Author->value,
        'term' => 'Inactive User',
        'region' => 'US',
    ]);

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(false);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')->never();

    $catalogSearch = new CatalogSearchService($catalog, app(ContributorService::class));
    $service = new AutotrackingService($catalogSearch, $membership);

    $result = $service->runRule($rule);

    expect($result)->toBe(['tracked' => 0, 'skipped' => 0]);
});

it('returns error when catalog search fails', function () {
    $rule = AutotrackRule::factory()->create([
        'user_id' => $this->user->id,
        'search_type' => SearchType::Author->value,
        'term' => 'Failing Author',
        'region' => 'US',
    ]);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')
        ->with(SearchType::Author, 'Failing Author', Region::US)
        ->andThrow(new RuntimeException('API timeout'));

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);
    $membership->allows('maxTracked')->andReturn(PHP_INT_MAX);

    $catalogSearch = new CatalogSearchService($catalog, app(ContributorService::class));
    $service = new AutotrackingService($catalogSearch, $membership);

    $result = $service->runRule($rule);

    expect($result)->toBe('error');
});

it('runAll processes all rules and returns metrics', function () {
    $productA = ProductResult::fromApiResponse([
        'asin' => 'B07RULE_A',
        'title' => 'Book A',
        'subtitle' => null,
        'publisher_summary' => '',
        'runtime_length_min' => null,
        'release_date' => now()->subDay(1)->toIso8601String(),
        'authors' => [['name' => 'Author A']],
        'narrators' => [],
        'product_images' => [],
    ], Region::US);

    $productB = ProductResult::fromApiResponse([
        'asin' => 'B07RULE_B',
        'title' => 'Book B',
        'subtitle' => null,
        'publisher_summary' => '',
        'runtime_length_min' => null,
        'release_date' => now()->subDay(2)->toIso8601String(),
        'authors' => [['name' => 'Author B']],
        'narrators' => [],
        'product_images' => [],
    ], Region::US);

    AutotrackRule::factory()->create([
        'user_id' => $this->user->id,
        'search_type' => SearchType::Author->value,
        'term' => 'Author A',
        'region' => 'US',
    ]);

    AutotrackRule::factory()->create([
        'user_id' => $this->user->id,
        'search_type' => SearchType::Author->value,
        'term' => 'Author B',
        'region' => 'US',
    ]);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')
        ->with(SearchType::Author, 'Author A', Region::US)
        ->andReturn([$productA]);
    $catalog->expects('search')
        ->with(SearchType::Author, 'Author B', Region::US)
        ->andReturn([$productB]);

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);
    $membership->allows('maxTracked')->andReturn(PHP_INT_MAX);

    $catalogSearch = new CatalogSearchService($catalog, app(ContributorService::class));
    $service = new AutotrackingService($catalogSearch, $membership);

    Event::fake();

    // Verify rules exist before calling runAll
    expect(AutotrackRule::count())->toBe(2);
    expect(AutotrackRule::with('user')->get())->toHaveCount(2);

    $results = $service->runAll();

    expect($results['rules_processed'])->toBe(2);
    expect($results['tracked'])->toBe(2);
    expect($results['skipped'])->toBe(0);
    expect($results['errors'])->toBe(0);

    $trackings = Tracking::where('user_id', $this->user->id)->get();
    expect($trackings)->toHaveCount(2);

    Event::assertDispatched(NewReleaseAutoTracked::class, 2);
});

it('returns empty when search returns no results', function () {
    $rule = AutotrackRule::factory()->create([
        'user_id' => $this->user->id,
        'search_type' => SearchType::Author->value,
        'term' => 'Unknown Author',
        'region' => 'US',
    ]);

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('search')
        ->with(SearchType::Author, 'Unknown Author', Region::US)
        ->andReturn([]);

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);
    $membership->allows('maxTracked')->andReturn(PHP_INT_MAX);

    $catalogSearch = new CatalogSearchService($catalog, app(ContributorService::class));
    $service = new AutotrackingService($catalogSearch, $membership);

    $result = $service->runRule($rule);

    expect($result)->toBe(['tracked' => 0, 'skipped' => 0]);
    expect(Tracking::where('user_id', $this->user->id)->count())->toBe(0);
});
