<?php

namespace App\Events;

use App\Models\Audiobook;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewReleaseAutoTracked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Audiobook $audiobook,
    ) {}
}
