<?php

use App\Contracts\AudibleCatalog;
use App\Dto\RatingResult;
use App\Enums\Region;
use App\Models\Audiobook;
use App\Models\AvailabilityEvent;
use App\Models\Tracking;
use App\Models\User;
use App\Services\AvailabilityCheckService;

uses()->group('feature');

beforeEach(function () {
    $user = User::factory()->create();

    $this->audiobook = Audiobook::factory()->create([
        'asin' => 'B07DXYZ123',
        'region' => 'US',
        'availability' => 'available',
        'unavailable_strikes' => 0,
        'unavailable_since' => null,
        'next_availability_check_at' => null,
        'availability_checked_at' => null,
    ]);

    // Track it so the service considers it eligible
    $tracking = new Tracking;
    $tracking->user_id = $user->id;
    $tracking->audiobook_id = $this->audiobook->id;
    $tracking->source = 'auto';
    $tracking->save();
});

it('marks available when product is found in catalog', function () {
    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 100,
        overallAverage: 4.5, overallCount: 80,
        overallStar1: 5, overallStar2: 10, overallStar3: 15, overallStar4: 20, overallStar5: 30,
        storyAverage: 4.3, storyCount: 75,
        storyStar1: 8, storyStar2: 12, storyStar3: 18, storyStar4: 22, storyStar5: 25,
        performanceAverage: 4.7, performanceCount: 70,
        performanceStar1: 2, performanceStar2: 5, performanceStar3: 10, performanceStar4: 18, performanceStar5: 35,
        isZeroed: false,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new AvailabilityCheckService($catalog);
    $result = $service->checkOne($this->audiobook);

    expect($result)->toBe('available');

    $this->audiobook->refresh();
    expect($this->audiobook->availability)->toBe('available');
    expect($this->audiobook->unavailable_strikes)->toBe(0);
    expect($this->audiobook->availability_checked_at)->not->toBeNull();
    expect($this->audiobook->next_availability_check_at)->not->toBeNull();
});

it('increments strikes when product is not found and stays available below threshold', function () {
    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([]);

    $service = new AvailabilityCheckService($catalog);
    $result = $service->checkOne($this->audiobook);

    expect($result)->toBe('strike');

    $this->audiobook->refresh();
    expect($this->audiobook->availability)->toBe('available');
    expect($this->audiobook->unavailable_strikes)->toBe(1);
});

it('marks unavailable when strikes reach threshold', function () {
    $this->audiobook->unavailable_strikes = 1;
    $this->audiobook->save();

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([]);

    $service = new AvailabilityCheckService($catalog);
    $result = $service->checkOne($this->audiobook);

    expect($result)->toBe('unavailable');

    $this->audiobook->refresh();
    expect($this->audiobook->availability)->toBe('unavailable');
    expect($this->audiobook->unavailable_since)->not->toBeNull();
    expect($this->audiobook->unavailable_strikes)->toBe(2);

    expect(AvailabilityEvent::where('audiobook_id', $this->audiobook->id)->count())->toBe(1);
    expect(AvailabilityEvent::first()->from_state)->toBe('available');
    expect(AvailabilityEvent::first()->to_state)->toBe('unavailable');
});

it('recovers when product is found after being unavailable', function () {
    $this->audiobook->availability = 'unavailable';
    $this->audiobook->unavailable_since = now()->subDays(3);
    $this->audiobook->unavailable_strikes = 2;
    $this->audiobook->save();

    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 100,
        overallAverage: 4.5, overallCount: 80,
        overallStar1: 5, overallStar2: 10, overallStar3: 15, overallStar4: 20, overallStar5: 30,
        storyAverage: 4.3, storyCount: 75,
        storyStar1: 8, storyStar2: 12, storyStar3: 18, storyStar4: 22, storyStar5: 25,
        performanceAverage: 4.7, performanceCount: 70,
        performanceStar1: 2, performanceStar2: 5, performanceStar3: 10, performanceStar4: 18, performanceStar5: 35,
        isZeroed: false,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new AvailabilityCheckService($catalog);
    $result = $service->checkOne($this->audiobook);

    expect($result)->toBe('recovered');

    $this->audiobook->refresh();
    expect($this->audiobook->availability)->toBe('available');
    expect($this->audiobook->unavailable_since)->toBeNull();
    expect($this->audiobook->unavailable_strikes)->toBe(0);

    expect(AvailabilityEvent::where('audiobook_id', $this->audiobook->id)->count())->toBe(1);
    expect(AvailabilityEvent::first()->from_state)->toBe('unavailable');
    expect(AvailabilityEvent::first()->to_state)->toBe('available');
});

it('returns error when the API throws', function () {
    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')
        ->with(['B07DXYZ123'], Region::US)
        ->andThrow(new RuntimeException('API timeout'));

    $service = new AvailabilityCheckService($catalog);
    $result = $service->checkOne($this->audiobook);

    expect($result)->toBe('error');

    $this->audiobook->refresh();
    expect($this->audiobook->availability)->toBe('available');
    expect($this->audiobook->unavailable_strikes)->toBe(0);
    expect($this->audiobook->availability_checked_at)->not->toBeNull();
    expect($this->audiobook->next_availability_check_at)->not->toBeNull();
});

it('does not log duplicate event when already unavailable and stays unavailable', function () {
    $this->audiobook->availability = 'unavailable';
    $this->audiobook->unavailable_since = now()->subDays(3);
    $this->audiobook->unavailable_strikes = 2;
    $this->audiobook->save();

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([]);

    $service = new AvailabilityCheckService($catalog);
    $result = $service->checkOne($this->audiobook);

    expect($result)->toBe('unavailable');
    expect(AvailabilityEvent::where('audiobook_id', $this->audiobook->id)->count())->toBe(0);
});

it('stamps next_availability_check_at with jitter within expected range', function () {
    $rating = new RatingResult(
        asin: 'B07DXYZ123',
        numReviews: 100,
        overallAverage: 4.5, overallCount: 80,
        overallStar1: 5, overallStar2: 10, overallStar3: 15, overallStar4: 20, overallStar5: 30,
        storyAverage: 4.3, storyCount: 75,
        storyStar1: 8, storyStar2: 12, storyStar3: 18, storyStar4: 22, storyStar5: 25,
        performanceAverage: 4.7, performanceCount: 70,
        performanceStar1: 2, performanceStar2: 5, performanceStar3: 10, performanceStar4: 18, performanceStar5: 35,
        isZeroed: false,
    );

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchRatings')->with(['B07DXYZ123'], Region::US)->andReturn([$rating]);

    $service = new AvailabilityCheckService($catalog);
    $service->checkOne($this->audiobook);

    $this->audiobook->refresh();
    $expectedBase = now()->addDays(7);
    $jitterHours = (int) round(7 * 24 * 0.15);
    expect($this->audiobook->next_availability_check_at)
        ->toBeBetween($expectedBase->subHours($jitterHours), $expectedBase->addHours($jitterHours));
});
