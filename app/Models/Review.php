<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'guided_responses' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    protected $fillable = [
        'audiobook_id',
        'external_id',
        'source',
        'format',
        'author_name',
        'title',
        'body',
        'guided_responses',
        'rating_overall',
        'rating_story',
        'rating_performance',
        'related_url',
        'submitted_at',
    ];

    public function audiobook(): BelongsTo
    {
        return $this->belongsTo(Audiobook::class);
    }

    /**
     * Scope to reviews visible to a given member under their current thresholds.
     *
     * A review is hidden when any of the member's minimum ratings aren't met:
     *   rating_overall < min_overall OR
     *   rating_story   < min_story   OR
     *   rating_performance < min_performance
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where('rating_overall', '>=', $user->min_overall)
            ->where('rating_story', '>=', $user->min_story)
            ->where('rating_performance', '>=', $user->min_performance);
    }
}
