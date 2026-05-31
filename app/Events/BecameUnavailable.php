<?php

namespace App\Events;

use App\Models\Audiobook;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BecameUnavailable
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Audiobook $audiobook,
    ) {}
}
