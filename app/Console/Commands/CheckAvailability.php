<?php

namespace App\Console\Commands;

use App\Models\Audiobook;
use App\Services\AvailabilityCheckService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    'audiobook:check-availability
        {--asin= : Single ASIN to check (requires --region)}
        {--region= : Region for single-ASIN check (US|UK)}',
)]
#[Description('Check Audible availability for tracked audiobooks due for a probe')]
class CheckAvailability extends Command
{
    public function handle(AvailabilityCheckService $check): void
    {
        $asin = $this->option('asin');
        $region = $this->option('region');

        if ($asin !== null && $region !== null) {
            $this->checkSingle($check, $asin, $region);

            return;
        }

        if ($asin xor $region) {
            $this->error('Both --asin and --region are required when targeting a single title.');

            return;
        }

        $this->checkAllDue($check);
    }

    private function checkSingle(AvailabilityCheckService $check, string $asin, string $region): void
    {
        $audiobook = Audiobook::where('asin', $asin)
            ->where('region', $region)
            ->first();

        if ($audiobook === null) {
            $this->warn("Audiobook {$asin} ({$region}) not found in the database.");

            return;
        }

        $this->info("Checking {$asin} ({$region})...");
        $result = $check->checkOne($audiobook);
        $this->line("  → {$result}");
    }

    private function checkAllDue(AvailabilityCheckService $check): void
    {
        $due = Audiobook::whereHas('trackings')
            ->dueForAvailabilityCheck()
            ->get();

        $count = $due->count();

        if ($count === 0) {
            $this->info('No audiobooks due for an availability check.');

            return;
        }

        $this->info("Checking {$count} audiobook(s)...");

        $results = [
            'available' => 0,
            'unavailable' => 0,
            'strike' => 0,
            'recovered' => 0,
            'error' => 0,
        ];

        foreach ($due as $audiobook) {
            $result = $check->checkOne($audiobook);
            $results[$result] = ($results[$result] ?? 0) + 1;
            $this->line("  {$audiobook->asin} ({$audiobook->region}): {$result}");
        }

        $this->line('');
        $this->table(
            ['Result', 'Count'],
            array_map(fn (string $key, int $val) => [$key, $val], array_keys($results), array_values($results)),
        );
    }
}
