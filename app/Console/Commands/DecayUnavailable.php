<?php

namespace App\Console\Commands;

use App\Services\AvailabilityCheckService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    'audiobook:decay-unavailable',
)]
#[Description('Remove trackings for titles unavailable past the decay threshold')]
class DecayUnavailable extends Command
{
    public function handle(AvailabilityCheckService $check): void
    {
        $deleted = $check->decayStaleTrackings();

        if ($deleted === 0) {
            $this->info('No stale unavailable trackings to remove.');

            return;
        }

        $this->info("Removed {$deleted} tracking(s) for stale unavailable titles.");
    }
}
