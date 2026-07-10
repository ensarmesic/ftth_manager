<?php

namespace Database\Factories;

use App\Models\Odf;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Odf>
 */
class OdfFactory extends Factory
{
    protected $model = Odf::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'ODF-'.$this->faker->unique()->numberBetween(1, 999),
            'address' => $this->faker->streetAddress(),
            'fiber_capacity' => 144,
            'port_count' => 48,
            'latitude' => $this->faker->latitude(44.4, 44.6),
            'longitude' => $this->faker->longitude(18.6, 18.8),
        ];
    }
}
