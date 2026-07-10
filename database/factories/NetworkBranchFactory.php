<?php

namespace Database\Factories;

use App\Models\NetworkBranch;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkBranch>
 */
class NetworkBranchFactory extends Factory
{
    protected $model = NetworkBranch::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Sekundarni krak '.$this->faker->unique()->numberBetween(1, 999),
            'type' => 'secondary',
            'sort_order' => 0,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['type' => 'primary', 'name' => 'Glavni krak '.$this->faker->unique()->numberBetween(1, 999)]);
    }
}
