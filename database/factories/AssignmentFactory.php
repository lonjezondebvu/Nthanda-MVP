<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Lesson;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assignment> */
class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'instructions' => fake()->sentence(),
            'due_at' => now()->addWeek(),
            'teacher_id' => User::factory()->state(['role' => 'teacher']),
            'lesson_id' => function (): int {
                $key = fake()->uuid();

                return Lesson::create(['unit_id' => Unit::factory()->create()->id, 'manifest_id' => $key, 'version' => '1.0.0', 'title' => 'Learning activity', 'sha256' => hash('sha256', $key), 'directory' => 'imports/'.$key, 'report' => []])->id;
            },
            'school_class_id' => fn (array $attributes): int => Lesson::findOrFail($attributes['lesson_id'])->unit->subject->school_class_id,
        ];
    }
}
