<?php

namespace App\Models;

use Database\Factories\TrackingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tracking extends Model
{
    /** @use HasFactory<TrackingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'audiobook_id',
        'source',
    ];

    protected function casts(): array
    {
        return [
            //
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audiobook(): BelongsTo
    {
        return $this->belongsTo(Audiobook::class);
    }

    public function scopeSource($query, string $source)
    {
        return $query->where('source', $source);
    }
}
