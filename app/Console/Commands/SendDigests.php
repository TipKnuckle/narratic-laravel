<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DigestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    'audiobook:send-digests
        {frequency=daily : Digest frequency to target (daily|weekly)}',
)]
#[Description('Assemble and send digests for users at the given frequency')]
class SendDigests extends Command
{
    public function handle(DigestService $digest): void
    {
        $frequency = $this->argument('frequency');

        if (! in_array($frequency, ['daily', 'weekly'])) {
            $this->error("Invalid frequency '{$frequency}'. Use 'daily' or 'weekly'.");

            return;
        }

        $targetCount = User::where('digest_frequency', $frequency)->count();

        if ($targetCount === 0) {
            $this->info("No users with digest_frequency '{$frequency}'.");

            return;
        }

        $this->info("Sending {$frequency} digests for {$targetCount} user(s)...");

        $results = $digest->sendForFrequency($frequency);

        $this->line('');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Sent', $results['sent']],
                ['Skipped (empty)', $results['skipped']],
                ['Errors', $results['errors']],
            ],
        );
    }
}
