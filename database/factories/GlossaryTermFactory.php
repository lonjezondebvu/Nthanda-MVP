<?php

namespace Database\Factories;

use App\Models\GlossaryTerm;
use App\Models\Lesson;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GlossaryTerm> */
class GlossaryTermFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lesson_id' => function (): int {
                $key = fake()->uuid();

                return Lesson::create(['unit_id' => Unit::factory()->create()->id, 'manifest_id' => $key, 'version' => '1', 'title' => 'Learning vocabulary', 'sha256' => hash('sha256', $key), 'directory' => 'imports/'.$key, 'report' => []])->id;
            },
            'term' => fake()->word(),
            'explanation_en' => fake()->sentence(),
            'explanation_ny' => null,
            'pronunciation_hint' => null,
            'reviewed_at' => null,
            'reviewer_id' => null,
        ];
    }
}
