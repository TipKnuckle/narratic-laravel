<?php

namespace Database\Factories;

use App\Models\Audiobook;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    public function definition(): array
    {
        $format = fake()->randomElement(['freeform', 'guided']);

        return [
            'audiobook_id' => Audiobook::factory(),
            'external_id' => fake()->unique()->regexify('[A-Za-z0-9]{20}'),
            'source' => 'audible',
            'format' => $format,
            'author_name' => fake()->name(),
            'title' => fake()->boolean(70) ? fake()->sentence(4) : null,
            'body' => $format === 'freeform' ? fake()->paragraph() : null,
            'guided_responses' => $format === 'guided'
                ? [['question' => fake()->sentence(), 'answer' => fake()->sentence()]]
                : null,
            'rating_overall' => fake()->numberBetween(1, 5),
            'rating_story' => fake()->numberBetween(1, 5),
            'rating_performance' => fake()->numberBetween(1, 5),
            'related_url' => null,
            'submitted_at' => fake()->dateTimeBetween('-6 months'),
        ];
    }

    public function audiofile(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'audiofile',
            'format' => null,
            'body' => fake()->paragraph(),
            'guided_responses' => null,
            'related_url' => fake()->url(),
            'author_name' => sprintf('%s. %s (%d)', chr(fake()->numberBetween(65, 90)), chr(fake()->numberBetween(65, 90)), fake()->year()),
        ]);
    }
}
