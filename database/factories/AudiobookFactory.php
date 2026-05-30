<?php

namespace Database\Factories;

use App\Models\Audiobook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Audiobook>
 */
class AudiobookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'asin' => fake()->regexify('[A-Z0-9]{10}'),
            'region' => fake()->randomElement(['US', 'UK']),
            'title' => fake()->unique()->sentence(3),
            'subtitle' => fake()->boolean(30) ? fake()->sentence(2) : null,
            'description' => fake()->paragraph(),
            'runtime_minutes' => fake()->numberBetween(300, 900),
            'cover_image_url' => fake()->imageUrl(),
            'published_at' => fake()->dateTimeBetween('-2 years', '+30 days'),
            'ratings_synced_at' => null,
            'reviews_pending' => false,
            'availability' => 'available',
            'unavailable_since' => null,
            'unavailable_strikes' => 0,
            'availability_checked_at' => null,
            'rating_zeroed_count' => 0,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability' => 'unavailable',
            'unavailable_since' => now()->subDays(15),
            'unavailable_strikes' => 2,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'reviews_pending' => true,
        ]);
    }
}
