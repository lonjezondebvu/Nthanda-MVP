<?php

namespace Database\Factories;

use App\Models\Subject;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'title' => $this->faker->unique()->words(2, true),
            'position' => 1,
        ];
    }
}
