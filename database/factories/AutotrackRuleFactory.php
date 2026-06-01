<?php

namespace Database\Factories;

use App\Models\AutotrackRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutotrackRule>
 */
class AutotrackRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'search_type' => fake()->randomElement(['author', 'narrator', 'title']),
            'term' => fake()->name(),
            'region' => 'US',
        ];
    }
}
