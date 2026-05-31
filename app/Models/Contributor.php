<?php

namespace App\Models;

use Database\Factories\ContributorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contributor extends Model
{
    /** @use HasFactory<ContributorFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

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
