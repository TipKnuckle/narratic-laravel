<?php

use App\Models\Contributor;
use App\Services\ContributorService;

uses()->group('feature');

it('creates a contributor from a name', function () {
    $service = app(ContributorService::class);

    $contributor = $service->findOrCreate('N.K. Jemisin');

    expect($contributor->name)->toBe('N.K. Jemisin');
    expect($contributor->slug)->toBe('nk-jemisin');

    $this->assertDatabaseHas('contributors', [
        'name' => 'N.K. Jemisin',
        'slug' => 'nk-jemisin',
    ]);
});

it('returns existing contributor by slug', function () {
    $existing = Contributor::factory()->create([
        'name' => 'N.K. Jemisin',
        'slug' => 'nk-jemisin',
    ]);

    $service = app(ContributorService::class);
    $result = $service->findOrCreate('N.K. Jemisin');

    expect($result->id)->toBe($existing->id);
});
