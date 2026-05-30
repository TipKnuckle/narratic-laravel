<?php

namespace Database\Factories;

use App\Models\RatingAspectSnapshot;
use App\Models\RatingSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatingAspectSnapshot>
 */
class RatingAspectSnapshotFactory extends Factory
{
    public function definition(): array
    {
        $count = fake()->numberBetween(10, 500);
        $distribution = collect(range(1, 5))->map(fn () => fake()->numberBetween(0, (int) ($count * 0.3)))->all();
        $total = array_sum($distribution);

        // Normalize so sum ≈ count
        $normalized = array_map(fn ($v) => (int) round($v / max($total, 1) * $count), $distribution);
        $normalized[4] += $count - array_sum($normalized);

        return [
            'rating_snapshot_id' => RatingSnapshot::factory(),
            'aspect' => fake()->randomElement(['overall', 'story', 'performance']),
            'average' => fake()->randomFloat(3, 1, 5),
            'count' => $count,
            'star_1' => $normalized[0],
            'star_2' => $normalized[1],
            'star_3' => $normalized[2],
            'star_4' => $normalized[3],
            'star_5' => $normalized[4],
        ];
    }
}
