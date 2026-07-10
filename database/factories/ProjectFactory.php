<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = 'FTTH '.$this->faker->unique()->city();

        return [
            'name' => $name,
            'code' => Str::upper(Str::slug($name)).'-'.$this->faker->unique()->numberBetween(1, 9999),
            'location' => $this->faker->city(),
            'investor' => $this->faker->company(),
            'status' => 'planning',
            'description' => $this->faker->sentence(),
        ];
    }
}
