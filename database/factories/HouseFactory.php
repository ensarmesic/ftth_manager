<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<House>
 */
class HouseFactory extends Factory
{
    protected $model = House::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'label' => 'K-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 3, '0', STR_PAD_LEFT),
            'address' => $this->faker->streetAddress(),
            'status' => 'planned',
            'latitude' => $this->faker->latitude(44.4, 44.6),
            'longitude' => $this->faker->longitude(18.6, 18.8),
        ];
    }
}
