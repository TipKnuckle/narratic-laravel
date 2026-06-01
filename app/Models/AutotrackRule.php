<?php

namespace App\Models;

use App\Enums\Region;
use App\Enums\SearchType;
use Database\Factories\AutotrackRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutotrackRule extends Model
{
    /** @use HasFactory<AutotrackRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'search_type' => SearchType::class,
            'region' => Region::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
