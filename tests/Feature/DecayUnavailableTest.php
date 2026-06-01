<?php

use App\Contracts\AudibleCatalog;
use App\Models\Audiobook;
use App\Models\Tracking;
use App\Models\User;
use App\Services\AvailabilityCheckService;

uses()->group('feature');

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('removes trackings for titles past the decay threshold', function () {
    $cutoff = now()->subDays(180);

    $audiobook = Audiobook::factory()->create([
        'asin' => 'B07STALE001',
        'region' => 'US',
        'availability' => 'unavailable',
        'unavailable_since' => $cutoff->subDay(),
    ]);

    Tracking::factory()->create([
        'user_id' => $this->user->id,
        'audiobook_id' => $audiobook->id,
    ]);

    $service = new AvailabilityCheckService(mock(AudibleCatalog::class));
    $deleted = $service->decayStaleTrackings();

    expect($deleted)->toBe(1);
    expect(Tracking::where('audiobook_id', $audiobook->id)->count())->toBe(0);
});

it('does not remove trackings for titles within the decay threshold', function () {
    $audiobook = Audiobook::factory()->create([
        'asin' => 'B07FRESH001',
        'region' => 'US',
        'availability' => 'unavailable',
        'unavailable_since' => now()->subDays(179),
    ]);

    Tracking::factory()->create([
        'user_id' => $this->user->id,
        'audiobook_id' => $audiobook->id,
    ]);

    $service = new AvailabilityCheckService(mock(AudibleCatalog::class));
    $deleted = $service->decayStaleTrackings();

    expect($deleted)->toBe(0);
    expect(Tracking::where('audiobook_id', $audiobook->id)->count())->toBe(1);
});

it('does not remove trackings for available titles even if old', function () {
    $audiobook = Audiobook::factory()->create([
        'asin' => 'B07AVAILABLE001',
        'region' => 'US',
        'availability' => 'available',
        'unavailable_since' => now()->subDays(200),
    ]);

    Tracking::factory()->create([
        'user_id' => $this->user->id,
        'audiobook_id' => $audiobook->id,
    ]);

    $service = new AvailabilityCheckService(mock(AudibleCatalog::class));
    $deleted = $service->decayStaleTrackings();

    expect($deleted)->toBe(0);
    expect(Tracking::where('audiobook_id', $audiobook->id)->count())->toBe(1);
});

it('does nothing when no stale titles exist', function () {
    $service = new AvailabilityCheckService(mock(AudibleCatalog::class));
    $deleted = $service->decayStaleTrackings();

    expect($deleted)->toBe(0);
});
