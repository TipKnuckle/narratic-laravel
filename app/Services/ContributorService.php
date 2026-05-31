<?php

namespace App\Services;

use App\Models\Contributor;
use Illuminate\Support\Str;

class ContributorService
{
    public function findOrCreate(string $name): Contributor
    {
        $slug = $this->slugify($name);

        return Contributor::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name],
        );
    }

    public function slugify(string $name): string
    {
        return Str::slug($name);
    }
}
