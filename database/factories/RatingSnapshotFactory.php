<?php

namespace Database\Factories;

use App\Models\Audiobook;
use App\Models\RatingSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatingSnapshot>
 */
class RatingSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'audiobook_id' => Audiobook::factory(),
            'recorded_at' => fake()->dateTimeBetween('-3 months'),
            'num_reviews' => fake()->numberBetween(10, 500),
        ];
    }
}
