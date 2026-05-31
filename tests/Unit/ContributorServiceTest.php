<?php

use App\Services\ContributorService;

it('slugifies names correctly', function () {
    $service = app(ContributorService::class);

    expect($service->slugify('Julia Whelan'))->toBe('julia-whelan');
    expect($service->slugify('N.K. Jemisin'))->toBe('nk-jemisin');
    expect($service->slugify('  Extra  Spaces  '))->toBe('extra-spaces');
});
