<?php

namespace App\Models;

use Database\Factories\AudiobookFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Audiobook extends Model
{
    /** @use HasFactory<AudiobookFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'ratings_synced_at' => 'datetime',
            'next_check_at' => 'datetime',
            'reviews_pending' => 'boolean',
            'unavailable_since' => 'datetime',
            'availability_checked_at' => 'datetime',
        ];
    }

    protected $fillable = [
        'asin',
        'region',
        'title',
        'subtitle',
        'description',
        'runtime_minutes',
        'cover_image_url',
        'published_at',
        'ratings_synced_at',
        'next_check_at',
        'reviews_pending',
        'availability',
        'unavailable_since',
        'unavailable_strikes',
        'availability_checked_at',
        'rating_zeroed_count',
    ];

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(Contributor::class)
            ->as('pivot')
            ->withPivot('role')
            ->using(AudiobookContributor::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function ratingSnapshots(): HasMany
    {
        return $this->hasMany(RatingSnapshot::class);
    }

    public function availabilityEvents(): HasMany
    {
        return $this->hasMany(AvailabilityEvent::class);
    }

    public function trackings(): HasMany
    {
        return $this->hasMany(Tracking::class);
    }

    /**
     * Scope to audiobooks due for a ratings sync.
     *
     * A title is due when its scheduled `next_check_at` has passed (or was
     * never set). The interval behind `next_check_at` is the adaptive cadence
     * in docs/spec/05-adr-ratings-sync-cadence.md. Pre-orders (future
     * `published_at`) are excluded. Ordered by `next_check_at` ascending —
     * nulls sort first, which is correct since a null means "due now", so the
     * most-overdue titles are claimed first and nothing starves under backlog.
     */
    public function scopeDueForRatingsSync(Builder $query, ?int $maxPerRun = null): Builder
    {
        $now = now();
        $limit = $maxPerRun ?? (int) config('narratic.ratings_sync.batch_limit', 200);

        return $query
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                // Exclude pre-orders: published_at is null OR in the past
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', $now);
            })
            ->orderByRaw('next_check_at is null desc')
            ->orderBy('next_check_at', 'asc')
            ->limit($limit);
    }
}
