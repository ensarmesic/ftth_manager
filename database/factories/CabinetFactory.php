<?php

namespace Database\Factories;

use App\Models\Cabinet;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cabinet>
 */
class CabinetFactory extends Factory
{
    protected $model = Cabinet::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'FTTH 1-'.$this->faker->unique()->numberBetween(1, 999),
            'address' => $this->faker->streetAddress(),
            'splitter_count' => 3,
            'ports_per_splitter' => 4,
            'latitude' => $this->faker->latitude(44.4, 44.6),
            'longitude' => $this->faker->longitude(18.6, 18.8),
        ];
    }
}
