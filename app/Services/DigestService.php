<?php

namespace App\Services;

use App\Contracts\Mailer;
use App\Contracts\Membership;
use App\Dto\DigestContent;
use App\Models\AvailabilityEvent;
use App\Models\RatingSnapshot;
use App\Models\Review;
use App\Models\Tracking;
use App\Models\User;
use Carbon\CarbonImmutable;

class DigestService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Membership $membership,
    ) {}

    /**
     * Send digests for all users at the given frequency.
     *
     * Returns metrics:
     *   - 'sent'     => count of digests delivered
     *   - 'skipped'  => count of users with empty digests
     *   - 'errors'   => count of users where assembly or delivery failed
     */
    public function sendForFrequency(string $frequency): array
    {
        $results = [
            'sent' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        User::where('digest_frequency', $frequency)->chunkById(50, function ($users) use (&$results) {
            foreach ($users as $user) {
                try {
                    $outcome = $this->sendForUser($user);
                } catch (\Throwable $e) {
                    $outcome = 'error';
                }

                if ($outcome === 'error') {
                    $results['errors']++;
                } elseif ($outcome === 'sent') {
                    $results['sent']++;
                } else {
                    $results['skipped']++;
                }
            }
        });

        return $results;
    }

    /**
     * Assemble and send a digest for a single user.
     *
     * @return 'sent'|'skipped'|'error'
     */
    public function sendForUser(User $user): string
    {
        if (! $this->membership->isActive($user)) {
            return 'skipped';
        }

        $windowStart = $user->last_digest_at
            ? CarbonImmutable::instance($user->last_digest_at)
            : CarbonImmutable::now()->subDays((int) config('narratic.digests.initial_lookback_days', 7));

        $windowEnd = CarbonImmutable::now();

        $trackedAudiobookIds = $user->trackings()->pluck('audiobook_id')->all();

        if (empty($trackedAudiobookIds)) {
            return 'skipped';
        }

        $content = $this->assembleDigest($user, $trackedAudiobookIds, $windowStart, $windowEnd);

        if ($content->isEmpty()) {
            return 'skipped';
        }

        try {
            $this->mailer->send($user, $content);
        } catch (\Throwable $e) {
            return 'error';
        }

        $user->last_digest_at = $windowEnd;
        $user->save();

        return 'sent';
    }

    /**
     * Assemble digest content from fact tables for a user's tracked titles.
     *
     * @param  int[]  $audiobookIds
     */
    private function assembleDigest(
        User $user,
        array $audiobookIds,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): DigestContent {
        $newReviews = $this->newReviewsInWindow($user, $audiobookIds, $windowStart, $windowEnd);
        $ratingChanges = $this->ratingChangesInWindow($user, $audiobookIds, $windowStart);
        $availabilityChanges = $this->availabilityChangesInWindow($audiobookIds, $windowStart, $windowEnd);
        $newReleases = $this->newReleasesInWindow($user, $audiobookIds, $windowStart, $windowEnd);

        return new DigestContent(
            newReviews: $newReviews,
            ratingChanges: $ratingChanges,
            availabilityChanges: $availabilityChanges,
            newReleases: $newReleases,
        );
    }

    /**
     * Find new reviews within the window that meet the user's preferences.
     *
     * @param  int[]  $audiobookIds
     * @return Review[]
     */
    private function newReviewsInWindow(
        User $user,
        array $audiobookIds,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): array {
        if (! $user->review_notifications_enabled) {
            return [];
        }

        $ageGateDays = (int) config('narratic.digests.review_age_gate_days', 30);
        $reviewCutoff = $windowEnd->subDays($ageGateDays);

        return Review::whereIn('audiobook_id', $audiobookIds)
            ->where('created_at', '>', $windowStart)
            ->where('created_at', '<=', $windowEnd)
            ->where('submitted_at', '>=', $reviewCutoff)
            ->where('rating_overall', '>=', $user->min_overall)
            ->where('rating_story', '>=', $user->min_story)
            ->where('rating_performance', '>=', $user->min_performance)
            ->with('audiobook')
            ->get()
            ->all();
    }

    /**
     * Find rating changes by comparing snapshots at the window boundary.
     *
     * @param  int[]  $audiobookIds
     * @return array<int, array{previous: RatingSnapshot, current: RatingSnapshot}>
     */
    private function ratingChangesInWindow(
        User $user,
        array $audiobookIds,
        CarbonImmutable $windowStart,
    ): array {
        if (! $user->ratings_notifications_enabled) {
            return [];
        }

        // Fetch latest snapshot per audiobook (the "current"), with audiobook pre-loaded
        $currentSnapshots = RatingSnapshot::whereIn('audiobook_id', $audiobookIds)
            ->orderBy('recorded_at', 'desc')
            ->with('audiobook')
            ->get()
            ->groupBy('audiobook_id')
            ->map(fn ($group) => $group->first());

        // Fetch the snapshot just before the window start (the "previous"), with audiobook pre-loaded
        $previousSnapshots = RatingSnapshot::whereIn('audiobook_id', $audiobookIds)
            ->where('recorded_at', '<=', $windowStart)
            ->orderBy('recorded_at', 'desc')
            ->with('audiobook')
            ->get()
            ->groupBy('audiobook_id')
            ->map(fn ($group) => $group->first());

        $changes = [];

        foreach ($currentSnapshots as $audiobookId => $current) {
            $previous = $previousSnapshots->get($audiobookId);

            if ($previous === null) {
                continue;
            }

            if ($previous->id === $current->id) {
                continue;
            }

            if ($previous->num_reviews !== $current->num_reviews) {
                $changes[$audiobookId] = [
                    'previous' => $previous,
                    'current' => $current,
                ];
            }
        }

        return $changes;
    }

    /**
     * Find availability events within the window for tracked titles.
     *
     * @param  int[]  $audiobookIds
     * @return AvailabilityEvent[]
     */
    private function availabilityChangesInWindow(
        array $audiobookIds,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): array {
        return AvailabilityEvent::whereIn('audiobook_id', $audiobookIds)
            ->where('occurred_at', '>=', $windowStart)
            ->where('occurred_at', '<=', $windowEnd)
            ->orderBy('occurred_at')
            ->with('audiobook')
            ->get()
            ->all();
    }

    /**
     * Find auto-tracked titles created within the window.
     *
     * @param  int[]  $audiobookIds
     * @return Tracking[]
     */
    private function newReleasesInWindow(
        User $user,
        array $audiobookIds,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): array {
        return $user->trackings()
            ->where('source', 'auto')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $windowEnd)
            ->with('audiobook')
            ->get()
            ->all();
    }
}
