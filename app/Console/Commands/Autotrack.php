<?php

namespace App\Console\Commands;

use App\Models\AutotrackRule;
use App\Services\AutotrackingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    'audiobook:autotrack',
)]
#[Description('Run autotrack rules to discover and track new releases from Audible')]
class Autotrack extends Command
{
    public function handle(AutotrackingService $autotracking): void
    {
        $ruleCount = AutotrackRule::count();

        if ($ruleCount === 0) {
            $this->info('No autotrack rules configured.');

            return;
        }

        $this->info("Running {$ruleCount} autotrack rule(s)...");

        $results = $autotracking->runAll();

        $this->line('');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Rules processed', $results['rules_processed']],
                ['New trackings created', $results['tracked']],
                ['Skipped (duplicate/outside window/cap)', $results['skipped']],
                ['Errors', $results['errors']],
            ],
        );
    }
}
