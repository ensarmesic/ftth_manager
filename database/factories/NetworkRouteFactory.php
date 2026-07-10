<?php

namespace Database\Factories;

use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkRoute>
 */
class NetworkRouteFactory extends Factory
{
    protected $model = NetworkRoute::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Sekundarni krak '.$this->faker->unique()->numberBetween(1, 999),
            'route_type' => 'distribution',
            'installation_type' => 'underground',
            'duct_length_m' => $this->faker->numberBetween(20, 400),
            'fiber_length_m' => $this->faker->numberBetween(20, 400),
            'fiber_count' => 12,
            'microduct_count' => 1,
            'microduct_type' => '14/10',
            'status' => 'planned',
            'path' => [[44.4493, 18.6498], [44.4503, 18.6508]],
        ];
    }

    public function trench(): static
    {
        return $this->state(fn () => [
            'name' => 'Glavni rov '.$this->faker->unique()->numberBetween(1, 999),
            'route_type' => 'trench',
            'fiber_length_m' => 0,
            'fiber_count' => null,
            'microduct_count' => 0,
            'microduct_type' => null,
        ]);
    }

    public function drop(): static
    {
        return $this->state(fn () => [
            'name' => 'Drop '.$this->faker->unique()->numberBetween(1, 999),
            'route_type' => 'drop',
            'fiber_count' => 4,
            'microduct_type' => '10/8',
        ]);
    }
}
