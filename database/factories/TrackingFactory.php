<?php

namespace Database\Factories;

use App\Models\Audiobook;
use App\Models\Tracking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tracking>
 */
class TrackingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'audiobook_id' => Audiobook::factory(),
            'source' => 'manual',
        ];
    }

    public function auto(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'auto',
        ]);
    }
}
