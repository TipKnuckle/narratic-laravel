<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected function casts(): array
    {
        return [
            'guided_responses' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    protected $fillable = [
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
