<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Audiobook extends Model
{
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'ratings_synced_at' => 'datetime',
            'reviews_pending' => 'boolean',
            'unavailable_since' => 'datetime',
            'availability_checked_at' => 'datetime',
        ];
    }

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
}
