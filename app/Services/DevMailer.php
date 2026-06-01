<?php

namespace App\Services;

use App\Contracts\Mailer;
use App\Dto\DigestContent;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DevMailer implements Mailer
{
    public function send(User $user, DigestContent $content): void
    {
        Log::info('Digest sent (dev mode)', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'new_reviews' => count($content->newReviews),
            'rating_changes' => count($content->ratingChanges),
            'availability_changes' => count($content->availabilityChanges),
            'new_releases' => count($content->newReleases),
        ]);
    }
}
