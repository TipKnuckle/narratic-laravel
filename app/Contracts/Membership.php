<?php

namespace App\Contracts;

use App\Models\User;

interface Membership
{
    /** Check whether the member's subscription is currently active. */
    public function isActive(User $user): bool;

    /** Return the maximum number of titles this member may track. */
    public function maxTracked(User $user): int;
}
