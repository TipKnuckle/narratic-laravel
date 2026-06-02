<?php

use App\Contracts\Membership;
use App\Models\Audiobook;
use App\Models\AvailabilityEvent;
use App\Models\RatingSnapshot;
use App\Models\Review;
use App\Models\Tracking;
use App\Models\User;
use App\Services\DigestService;
use Tests\Fakes\FakeMailer;

uses()->group('feature');

beforeEach(function () {
    $this->user = User::factory()->create([
        'digest_frequency' => 'daily',
        'review_notifications_enabled' => true,
        'ratings_notifications_enabled' => true,
        'min_overall' => 0,
        'min_story' => 0,
        'min_performance' => 0,
        'last_digest_at' => null,
    ]);

    $this->audiobook = Audiobook::factory()->create([
        'asin' => 'B07DIGEST01',
        'region' => 'US',
    ]);

    Tracking::factory()->create([
        'user_id' => $this->user->id,
        'audiobook_id' => $this->audiobook->id,
        'source' => 'manual',
    ]);

    $this->mailer = new FakeMailer;
    $this->membership = mock(Membership::class);
    $this->membership->allows('isActive')->andReturn(true);
    $this->service = new DigestService($this->mailer, $this->membership);
});

it('sends digest with new reviews', function () {
    Review::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'source' => 'audible',
        'submitted_at' => now()->subDay(1),
        'rating_story' => 4,
        'rating_performance' => 5,
    ]);

    $result = $this->service->sendForUser($this->user);

    expect($result)->toBe('sent');
    expect($this->mailer->sendCount)->toBe(1);

    $this->user->refresh();
    expect($this->user->last_digest_at)->not->toBeNull();
});

it('skips digest when no new content', function () {
    expect($this->service->sendForUser($this->user))->toBe('skipped');
    expect($this->mailer->sendCount)->toBe(0);
});

it('skips digest when user is not active', function () {
    $this->membership->allows('isActive')->andReturn(false);

    expect($this->service->sendForUser($this->user))->toBe('skipped');
    expect($this->mailer->sendCount)->toBe(0);
});

it('skips digest when user has no trackings', function () {
    Audiobook::factory()->create(['asin' => 'B07UNTRACKED', 'region' => 'US']);
    $this->user->trackings()->delete();

    expect($this->service->sendForUser($this->user))->toBe('skipped');
    expect($this->mailer->sendCount)->toBe(0);
});

it('sends digest with availability changes', function () {
    AvailabilityEvent::create([
        'audiobook_id' => $this->audiobook->id,
        'from_state' => 'available',
        'to_state' => 'unavailable',
        'occurred_at' => now()->subDay(1),
    ]);

    expect($this->service->sendForUser($this->user))->toBe('sent');
    expect($this->mailer->sendCount)->toBe(1);
});

it('sends digest with rating changes', function () {
    RatingSnapshot::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'recorded_at' => now()->subDays(10),
        'num_reviews' => 50,
    ]);

    RatingSnapshot::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'recorded_at' => now()->subDay(1),
        'num_reviews' => 100,
    ]);

    expect($this->service->sendForUser($this->user))->toBe('sent');
    expect($this->mailer->sendCount)->toBe(1);
});

it('sends digest with auto-tracked new releases', function () {
    $newAudiobook = Audiobook::factory()->create([
        'asin' => 'B07AUTOTRACK',
        'region' => 'US',
    ]);

    Tracking::factory()->create([
        'user_id' => $this->user->id,
        'audiobook_id' => $newAudiobook->id,
        'source' => 'auto',
        'created_at' => now()->subDay(1),
    ]);

    expect($this->service->sendForUser($this->user))->toBe('sent');
    expect($this->mailer->sendCount)->toBe(1);
});

it('filters reviews that do not meet minimum thresholds', function () {
    Review::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'source' => 'audible',
        'submitted_at' => now()->subDay(1),
        'rating_story' => 1,
        'rating_performance' => 1,
    ]);

    $this->user->min_story = 3;
    $this->user->min_performance = 3;

    expect($this->service->sendForUser($this->user))->toBe('skipped');
});

it('includes a late-ingested review whose submitted_at predates the window', function () {
    // Window is (yesterday, now]
    $this->user->last_digest_at = now()->subDay();
    $this->user->save();

    // Submitted 5 days ago (before the window starts), but ingested just now
    // (inside the window) and still inside the 30-day freshness gate.
    Review::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'source' => 'audible',
        'submitted_at' => now()->subDays(5),
        'rating_overall' => 4,
        'rating_story' => 4,
        'rating_performance' => 5,
    ]);

    expect($this->service->sendForUser($this->user))->toBe('sent');
    expect($this->mailer->sendCount)->toBe(1);
});

it('excludes a review whose submitted_at is past the 30-day freshness gate', function () {
    Review::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'source' => 'audible',
        'submitted_at' => now()->subDays(60),
        'rating_overall' => 5,
        'rating_story' => 5,
        'rating_performance' => 5,
    ]);

    expect($this->service->sendForUser($this->user))->toBe('skipped');
});

it('excludes a review whose overall rating is below the member minimum', function () {
    Review::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'source' => 'audible',
        'submitted_at' => now()->subDay(1),
        'rating_overall' => 1,
        'rating_story' => 5,
        'rating_performance' => 5,
    ]);

    $this->user->min_overall = 3;
    $this->user->save();

    expect($this->service->sendForUser($this->user))->toBe('skipped');
});

it('sendForFrequency processes only users at the given frequency', function () {
    $dailyA = User::factory()->create(['digest_frequency' => 'daily', 'last_digest_at' => null]);
    $bookA = Audiobook::factory()->create(['asin' => 'B07FREQDA', 'region' => 'US']);
    Tracking::factory()->create(['user_id' => $dailyA->id, 'audiobook_id' => $bookA->id, 'source' => 'manual']);
    Review::factory()->create([
        'audiobook_id' => $bookA->id, 'source' => 'audible',
        'submitted_at' => now()->subDay(1), 'rating_story' => 4, 'rating_performance' => 5,
    ]);

    $dailyB = User::factory()->create(['digest_frequency' => 'daily', 'last_digest_at' => null]);
    $bookB = Audiobook::factory()->create(['asin' => 'B07FREQDB', 'region' => 'US']);
    Tracking::factory()->create(['user_id' => $dailyB->id, 'audiobook_id' => $bookB->id, 'source' => 'manual']);
    Review::factory()->create([
        'audiobook_id' => $bookB->id, 'source' => 'audible',
        'submitted_at' => now()->subDay(1), 'rating_story' => 4, 'rating_performance' => 5,
    ]);

    // One weekly user (should be excluded)
    User::factory()->create(['digest_frequency' => 'weekly', 'last_digest_at' => null]);

    $results = $this->service->sendForFrequency('daily');

    // beforeEach creates a daily user with no content → 1 skip
    expect($results['sent'])->toBe(2);
    expect($results['skipped'])->toBe(1);
    expect($results['errors'])->toBe(0);
});

it('eager-loads audiobook relation on all digest items', function () {
    $bookB = Audiobook::factory()->create(['asin' => 'B07EAGER02', 'region' => 'US']);
    Tracking::factory()->create(['user_id' => $this->user->id, 'audiobook_id' => $bookB->id, 'source' => 'manual']);

    // Reviews
    Review::factory()->create([
        'audiobook_id' => $this->audiobook->id, 'source' => 'audible',
        'submitted_at' => now()->subDay(1), 'rating_overall' => 4, 'rating_story' => 4, 'rating_performance' => 5,
    ]);
    Review::factory()->create([
        'audiobook_id' => $bookB->id, 'source' => 'audible',
        'submitted_at' => now()->subDay(1), 'rating_overall' => 4, 'rating_story' => 4, 'rating_performance' => 5,
    ]);

    // Availability events
    AvailabilityEvent::create(['audiobook_id' => $this->audiobook->id, 'from_state' => 'available', 'to_state' => 'unavailable', 'occurred_at' => now()->subDay(1)]);
    AvailabilityEvent::create(['audiobook_id' => $bookB->id, 'from_state' => 'available', 'to_state' => 'unavailable', 'occurred_at' => now()->subDay(1)]);

    // Snapshots for rating changes
    RatingSnapshot::factory()->create(['audiobook_id' => $this->audiobook->id, 'recorded_at' => now()->subDays(10), 'num_reviews' => 50]);
    RatingSnapshot::factory()->create(['audiobook_id' => $this->audiobook->id, 'recorded_at' => now()->subDay(1), 'num_reviews' => 100]);
    RatingSnapshot::factory()->create(['audiobook_id' => $bookB->id, 'recorded_at' => now()->subDays(10), 'num_reviews' => 20]);
    RatingSnapshot::factory()->create(['audiobook_id' => $bookB->id, 'recorded_at' => now()->subDay(1), 'num_reviews' => 30]);

    // Auto-tracked new release
    $bookC = Audiobook::factory()->create(['asin' => 'B07EAGER03', 'region' => 'US']);
    Tracking::factory()->create(['user_id' => $this->user->id, 'audiobook_id' => $bookC->id, 'source' => 'auto', 'created_at' => now()->subDay(1)]);

    $this->service->sendForUser($this->user);

    $content = $this->mailer->lastContent;

    foreach ($content->newReviews as $review) {
        expect($review->relationLoaded('audiobook'))->toBeTrue();
    }
    foreach ($content->availabilityChanges as $event) {
        expect($event->relationLoaded('audiobook'))->toBeTrue();
    }
    foreach ($content->ratingChanges as $change) {
        expect($change['current']->relationLoaded('audiobook'))->toBeTrue();
    }
    foreach ($content->newReleases as $tracking) {
        expect($tracking->relationLoaded('audiobook'))->toBeTrue();
    }
});
