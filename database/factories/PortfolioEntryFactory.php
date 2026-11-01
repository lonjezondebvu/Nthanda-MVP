<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\PortfolioEntry;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PortfolioEntry> */
class PortfolioEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'A practical observation',
            'reflection' => 'I observed a change and recorded what I learned.',
            'lesson_id' => function (): int {
                $key = fake()->uuid();

                return Lesson::create(['unit_id' => Unit::factory()->create()->id, 'manifest_id' => $key, 'version' => '1.0.0', 'title' => 'Learning activity', 'sha256' => hash('sha256', $key), 'directory' => 'imports/'.$key, 'report' => []])->id;
            },
            'school_class_id' => fn (array $attributes): int => Lesson::findOrFail($attributes['lesson_id'])->unit->subject->school_class_id,
            'user_id' => fn (array $attributes): int => User::factory()->create(['school_class_id' => $attributes['school_class_id']])->id,
            'status' => 'submitted',
        ];
    }
}
