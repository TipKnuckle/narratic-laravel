<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatingAspectSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'aspect',
        'average',
        'count',
        'star_1',
        'star_2',
        'star_3',
        'star_4',
        'star_5',
    ];

    protected function casts(): array
    {
        return [
            'average' => 'float',
        ];
    }

    public function ratingSnapshot(): BelongsTo
    {
        return $this->belongsTo(RatingSnapshot::class);
    }
}
