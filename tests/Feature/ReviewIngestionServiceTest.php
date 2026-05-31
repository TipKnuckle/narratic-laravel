<?php

use App\Contracts\AudibleCatalog;
use App\Dto\ReviewResult;
use App\Enums\Region;
use App\Events\ReviewPublished;
use App\Models\Audiobook;
use App\Services\ReviewIngestionService;
use Illuminate\Support\Facades\Event;

uses()->group('feature');

beforeEach(function () {
    $this->audiobook = Audiobook::factory()->create([
        'asin' => 'B07DXYZ123',
        'region' => 'US',
        'reviews_pending' => true,
    ]);
});

it('ingests new freeform reviews', function () {
    $reviews = [
        new ReviewResult(
            externalId: 'rev-001',
            title: 'Great listen',
            submittedAt: '2025-06-01T12:00:00Z',
            authorName: 'Alice',
            format: 'freeform',
            body: 'Loved the narration!',
            guidedResponses: null,
            ratingOverall: 5,
            ratingStory: 4,
            ratingPerformance: 5,
            relatedUrl: null,
        ),
        new ReviewResult(
            externalId: 'rev-002',
            title: 'Okay',
            submittedAt: '2025-06-02T12:00:00Z',
            authorName: 'Bob',
            format: 'freeform',
            body: 'Decent but slow.',
            guidedResponses: null,
            ratingOverall: 3,
            ratingStory: 3,
            ratingPerformance: 4,
            relatedUrl: null,
        ),
    ];

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchReviews')->with('B07DXYZ123', Region::US)->andReturn($reviews);

    Event::fake();

    $service = new ReviewIngestionService($catalog);
    $result = $service->ingestOne($this->audiobook);

    expect($result)->toBe(['ingested' => 2, 'skipped' => 0]);

    expect($this->audiobook->reviews()->count())->toBe(2);

    $first = $this->audiobook->reviews()->where('external_id', 'rev-001')->first();
    expect($first->format)->toBe('freeform');
    expect($first->body)->toBe('Loved the narration!');
    expect($first->guided_responses)->toBeNull();

    Event::assertDispatched(ReviewPublished::class, 2);
});

it('ingests guided reviews with structured responses', function () {
    $reviews = [
        new ReviewResult(
            externalId: 'rev-003',
            title: 'Nice',
            submittedAt: '2025-06-01T12:00:00Z',
            authorName: 'Charlie',
            format: 'guided',
            body: null,
            guidedResponses: [
                ['question' => 'Was the narration engaging?', 'response' => 'Yes, very!'],
                ['question' => 'Would you recommend?', 'response' => 'Absolutely.'],
            ],
            ratingOverall: 4,
            ratingStory: 4,
            ratingPerformance: 5,
            relatedUrl: null,
        ),
    ];

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchReviews')->with('B07DXYZ123', Region::US)->andReturn($reviews);

    $service = new ReviewIngestionService($catalog);
    $result = $service->ingestOne($this->audiobook);

    expect($result)->toBe(['ingested' => 1, 'skipped' => 0]);

    $review = $this->audiobook->reviews()->first();
    expect($review->format)->toBe('guided');
    expect($review->body)->toBeNull();
    expect($review->guided_responses)->toBeArray();
    expect($review->guided_responses[0]['question'])->toBe('Was the narration engaging?');
});

it('dedupes on external_id', function () {
    // Pre-stored review with same external_id
    $this->audiobook->reviews()->create([
        'external_id' => 'rev-001',
        'source' => 'audible',
        'format' => 'freeform',
        'author_name' => 'Alice',
        'body' => 'Existing review',
        'submitted_at' => now(),
    ]);

    $reviews = [
        new ReviewResult(
            externalId: 'rev-001',
            title: 'Duplicate',
            submittedAt: '2025-06-01T12:00:00Z',
            authorName: 'Alice',
            format: 'freeform',
            body: 'Loved the narration!',
            guidedResponses: null,
            ratingOverall: 5,
            ratingStory: 4,
            ratingPerformance: 5,
            relatedUrl: null,
        ),
        new ReviewResult(
            externalId: 'rev-002',
            title: 'New review',
            submittedAt: '2025-06-02T12:00:00Z',
            authorName: 'Bob',
            format: 'freeform',
            body: 'Fresh!',
            guidedResponses: null,
            ratingOverall: 3,
            ratingStory: 3,
            ratingPerformance: 4,
            relatedUrl: null,
        ),
    ];

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchReviews')->with('B07DXYZ123', Region::US)->andReturn($reviews);

    Event::fake();

    $service = new ReviewIngestionService($catalog);
    $result = $service->ingestOne($this->audiobook);

    // One skipped (duplicate), one ingested (new)
    expect($result)->toBe(['ingested' => 1, 'skipped' => 1]);
    expect($this->audiobook->reviews()->count())->toBe(2);

    Event::assertDispatched(ReviewPublished::class, 1);
});

it('returns empty when API returns no reviews', function () {
    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchReviews')->with('B07DXYZ123', Region::US)->andReturn([]);

    $service = new ReviewIngestionService($catalog);
    $result = $service->ingestOne($this->audiobook);

    expect($result)->toBe(['ingested' => 0, 'skipped' => 0]);
    expect($this->audiobook->reviews()->count())->toBe(0);
});

it('returns error when API call fails', function () {
    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchReviews')->with('B07DXYZ123', Region::US)->andThrow(new RuntimeException('API timeout'));

    $service = new ReviewIngestionService($catalog);
    $result = $service->ingestOne($this->audiobook);

    expect($result)->toBe('error');
    expect($this->audiobook->reviews()->count())->toBe(0);
});

it('ingestPending processes all flagged audiobooks and clears flag', function () {
    Audiobook::factory()->create([
        'asin' => 'B07TEST001',
        'region' => 'US',
        'reviews_pending' => true,
    ]);

    $catalog = mock(AudibleCatalog::class);
    // Both audiobooks get fetchReviews called
    $catalog->expects('fetchReviews')
        ->with('B07DXYZ123', Region::US)
        ->andReturn([
            new ReviewResult('rev-100', 'Good', '2025-06-01T12:00:00Z', 'Alice', 'freeform', 'Nice!', null, 4, 4, 5, null),
        ]);
    $catalog->expects('fetchReviews')
        ->with('B07TEST001', Region::US)
        ->andReturn([]);

    $service = new ReviewIngestionService($catalog);
    $results = $service->ingestPending();

    expect($results['processed'])->toBe(2);
    expect($results['ingested'])->toBe(1);
    expect($results['skipped'])->toBe(0);
    expect($results['errors'])->toBe(0);

    // Both should have reviews_pending cleared
    expect(Audiobook::where('reviews_pending', true)->count())->toBe(0);
});

it('clears reviews_pending even on API failure', function () {
    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchReviews')
        ->with('B07DXYZ123', Region::US)
        ->andThrow(new RuntimeException('API timeout'));

    $service = new ReviewIngestionService($catalog);
    $results = $service->ingestPending();

    expect($results['errors'])->toBe(1);

    // Flag cleared even on error
    $this->audiobook->refresh();
    expect($this->audiobook->reviews_pending)->toBeFalse();
});

it('does not ingest reviews for other sources in dedupe check', function () {
    // Create an audiofile review with same external_id — should not affect
    // audible dedupe because we filter on source='audible'
    $this->audiobook->reviews()->create([
        'external_id' => 'rev-001',
        'source' => 'audiofile',
        'format' => 'freeform',
        'author_name' => 'Alice',
        'body' => 'Audiofile review',
        'submitted_at' => now(),
    ]);

    $reviews = [
        new ReviewResult(
            externalId: 'rev-001',
            title: 'Audible review',
            submittedAt: '2025-06-01T12:00:00Z',
            authorName: 'Alice',
            format: 'freeform',
            body: 'From Audible',
            guidedResponses: null,
            ratingOverall: 5,
            ratingStory: 4,
            ratingPerformance: 5,
            relatedUrl: null,
        ),
    ];

    $catalog = mock(AudibleCatalog::class);
    $catalog->expects('fetchReviews')->with('B07DXYZ123', Region::US)->andReturn($reviews);

    Event::fake();

    $service = new ReviewIngestionService($catalog);
    $result = $service->ingestOne($this->audiobook);

    // audiofile review should NOT block the audible review
    expect($result)->toBe(['ingested' => 1, 'skipped' => 0]);
    expect($this->audiobook->reviews()->count())->toBe(2);

    Event::assertDispatched(ReviewPublished::class, 1);
});
