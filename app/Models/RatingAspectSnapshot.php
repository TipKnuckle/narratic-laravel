<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatingAspectSnapshot extends Model
{
    public $timestamps = false;

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
