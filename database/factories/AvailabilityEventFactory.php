<?php

namespace Database\Factories;

use App\Models\Audiobook;
use App\Models\AvailabilityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityEvent>
 */
class AvailabilityEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'audiobook_id' => Audiobook::factory(),
            'from_state' => 'available',
            'to_state' => 'unavailable',
            'occurred_at' => fake()->dateTimeBetween('-1 month'),
        ];
    }

    public function becameAvailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_state' => 'unavailable',
            'to_state' => 'available',
        ]);
    }
}
