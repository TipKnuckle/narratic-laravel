<?php

use App\Contracts\Mailer;
use App\Contracts\Membership;
use App\Models\Audiobook;
use App\Models\AvailabilityEvent;
use App\Models\RatingSnapshot;
use App\Models\Review;
use App\Models\Tracking;
use App\Models\User;
use App\Services\DigestService;

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
});

it('sends digest with new reviews', function () {
    Review::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'source' => 'audible',
        'submitted_at' => now()->subDay(1),
        'rating_story' => 4,
        'rating_performance' => 5,
    ]);

    $mailer = mock(Mailer::class);
    $mailer->expects('send')->once();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('sent');

    $this->user->refresh();
    expect($this->user->last_digest_at)->not->toBeNull();
});

it('skips digest when no new content', function () {
    $mailer = mock(Mailer::class);
    $mailer->expects('send')->never();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('skipped');
});

it('skips digest when user is not active', function () {
    $mailer = mock(Mailer::class);
    $mailer->expects('send')->never();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(false);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('skipped');
});

it('skips digest when user has no trackings', function () {
    // Untracked audiobook — no tracking for this user
    Audiobook::factory()->create(['asin' => 'B07UNTRACKED', 'region' => 'US']);

    $mailer = mock(Mailer::class);
    $mailer->expects('send')->never();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    // Remove the tracking created in beforeEach
    $this->user->trackings()->delete();

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('skipped');
});

it('sends digest with availability changes', function () {
    AvailabilityEvent::create([
        'audiobook_id' => $this->audiobook->id,
        'from_state' => 'available',
        'to_state' => 'unavailable',
        'occurred_at' => now()->subDay(1),
    ]);

    $mailer = mock(Mailer::class);
    $mailer->expects('send')->once();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('sent');
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

    $mailer = mock(Mailer::class);
    $mailer->expects('send')->once();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('sent');
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

    $mailer = mock(Mailer::class);
    $mailer->expects('send')->once();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('sent');
});

it('filters reviews that do not meet minimum thresholds', function () {
    Review::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'source' => 'audible',
        'submitted_at' => now()->subDay(1),
        'rating_story' => 1,
        'rating_performance' => 1,
    ]);

    // Set high thresholds so the review is excluded
    $this->user->min_story = 3;
    $this->user->min_performance = 3;

    $mailer = mock(Mailer::class);
    $mailer->expects('send')->never();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('skipped');
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

    $mailer = mock(Mailer::class);
    $mailer->expects('send')->once();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('sent');
});

it('excludes a review whose submitted_at is past the 30-day freshness gate', function () {
    // Ingested today (inside the window) but submitted 60 days ago.
    Review::factory()->create([
        'audiobook_id' => $this->audiobook->id,
        'source' => 'audible',
        'submitted_at' => now()->subDays(60),
        'rating_overall' => 5,
        'rating_story' => 5,
        'rating_performance' => 5,
    ]);

    $mailer = mock(Mailer::class);
    $mailer->expects('send')->never();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('skipped');
});

it('excludes a review whose overall rating is below the member minimum', function () {
    // High story and performance, but a low overall — current rule was missing
    // the overall comparison, so this used to slip through.
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

    $mailer = mock(Mailer::class);
    $mailer->expects('send')->never();

    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $result = $service->sendForUser($this->user);

    expect($result)->toBe('skipped');
});

it('sendForFrequency processes only users at the given frequency', function () {
    // Two daily users with content
    $dailyA = User::factory()->create([
        'digest_frequency' => 'daily',
        'last_digest_at' => null,
    ]);
    $bookA = Audiobook::factory()->create(['asin' => 'B07FREQDA', 'region' => 'US']);
    Tracking::factory()->create(['user_id' => $dailyA->id, 'audiobook_id' => $bookA->id, 'source' => 'manual']);
    Review::factory()->create([
        'audiobook_id' => $bookA->id, 'source' => 'audible',
        'submitted_at' => now()->subDay(1), 'rating_story' => 4, 'rating_performance' => 5,
    ]);

    $dailyB = User::factory()->create([
        'digest_frequency' => 'daily',
        'last_digest_at' => null,
    ]);
    $bookB = Audiobook::factory()->create(['asin' => 'B07FREQDB', 'region' => 'US']);
    Tracking::factory()->create(['user_id' => $dailyB->id, 'audiobook_id' => $bookB->id, 'source' => 'manual']);
    Review::factory()->create([
        'audiobook_id' => $bookB->id, 'source' => 'audible',
        'submitted_at' => now()->subDay(1), 'rating_story' => 4, 'rating_performance' => 5,
    ]);

    // One weekly user (should be excluded)
    User::factory()->create(['digest_frequency' => 'weekly', 'last_digest_at' => null]);

    $mailer = mock(Mailer::class);
    $mailer->allows('send');
    $membership = mock(Membership::class);
    $membership->allows('isActive')->andReturn(true);

    $service = new DigestService($mailer, $membership);
    $results = $service->sendForFrequency('daily');

    // beforeEach creates a daily user with no content → 1 skip
    expect($results['sent'])->toBe(2);
    expect($results['skipped'])->toBe(1);
    expect($results['errors'])->toBe(0);
});
