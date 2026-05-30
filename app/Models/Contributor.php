<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contributor extends Model
{
    protected function casts(): array
    {
        return [
            //
        ];
    }

    public function audiobooks(): BelongsToMany
    {
        return $this->belongsToMany(Audiobook::class)
            ->as('pivot')
            ->withPivot('role')
            ->using(AudiobookContributor::class);
    }
}
