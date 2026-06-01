<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
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
}
