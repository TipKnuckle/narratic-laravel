<?php

namespace App\Models;

use Database\Factories\RatingSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RatingSnapshot extends Model
{
    /** @use HasFactory<RatingSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'recorded_at',
        'num_reviews',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    public function audiobook(): BelongsTo
    {
        return $this->belongsTo(Audiobook::class);
    }

    public function aspectSnapshots(): HasMany
    {
        return $this->hasMany(RatingAspectSnapshot::class);
    }
}
