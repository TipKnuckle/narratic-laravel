<?php

namespace App\Services;

use App\Contracts\Membership;
use App\Models\User;

/**
 * Development stub — treats every user as active with unlimited tracking.
 *
 * Swap this out when a billing library is chosen. The rest of the app
 * depends only on the Membership interface, so nothing else changes.
 */
class DevMembership implements Membership
{
    public function isActive(User $user): bool
    {
        return true;
    }

    public function maxTracked(User $user): int
    {
        return PHP_INT_MAX;
    }
}
