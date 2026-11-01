<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Lesson;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_assigns_only_lessons_in_their_class_and_can_archive_work(): void
    {
        $unit = Unit::factory()->create();
        $lesson = $this->lesson($unit);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($unit->subject->school_class_id);
        $url = '/teach/classes/'.$unit->subject->school_class_id.'/assignments';
        $data = ['title' => 'Explore leaves', 'lesson_id' => $lesson->id, 'instructions' => 'Read and explain.', 'due_at' => now()->addDays(3)->toIso8601String(), 'teacher_id' => 999];
        $this->actingAs($teacher)->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $assignment = Assignment::query()->firstOrFail();
        $this->assertSame($teacher->id, $assignment->teacher_id);
        $otherLesson = $this->lesson(Unit::factory()->create());
        $this->post($url, [...$data, 'lesson_id' => $otherLesson->id])->assertSessionHasErrors('lesson_id');
        $this->patch('/teach/assignments/'.$assignment->id, ['archived' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($assignment->fresh()->archived_at);
        $outsider = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($outsider)->post($url, $data)->assertForbidden();
        $this->patch('/teach/assignments/'.$assignment->id, ['archived' => false])->assertForbidden();
    }

    public function test_learner_sees_only_active_assignments_in_their_class_and_workspace(): void
    {
        $unit = Unit::factory()->create();
        $lesson = $this->lesson($unit);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id]);
        Assignment::create(['title' => 'My work', 'lesson_id' => $lesson->id, 'school_class_id' => $learner->school_class_id, 'teacher_id' => $teacher->id]);
        Assignment::create(['title' => 'Archived', 'lesson_id' => $lesson->id, 'school_class_id' => $learner->school_class_id, 'teacher_id' => $teacher->id, 'archived_at' => now()]);
        $other = $this->lesson(Unit::factory()->create());
        Assignment::create(['title' => 'Other class', 'lesson_id' => $other->id, 'school_class_id' => $other->unit->subject->school_class_id, 'teacher_id' => $teacher->id]);
        $this->actingAs($learner)->get('/assignments')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Assignments')->has('assignments.data', 1)->where('assignments.data.0.title', 'My work')->has('classes', 0));
        $this->post('/teach/classes/'.$learner->school_class_id.'/assignments', ['title' => 'Forbidden', 'lesson_id' => $lesson->id])->assertForbidden();
        $this->withSession(['studio_workspace' => 'draft'])->get('/assignments')->assertInertia(fn (AssertableInertia $page) => $page->has('assignments.data', 0));
    }

    public function test_assignment_input_is_validated_and_guests_must_sign_in(): void
    {
        $unit = Unit::factory()->create();
        $lesson = $this->lesson($unit);
        $classId = $unit->subject->school_class_id;
        $url = '/teach/classes/'.$classId.'/assignments';
        $this->get('/assignments')->assertRedirect('/login');
        $this->post($url, [])->assertRedirect('/login');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($classId);
        $this->actingAs($teacher)->post($url, [])->assertSessionHasErrors(['title', 'lesson_id']);
        $this->post($url, ['title' => str_repeat('x', 161), 'lesson_id' => $lesson->id, 'due_at' => now()->subDay()->toIso8601String(), 'instructions' => str_repeat('x', 5001)])->assertSessionHasErrors(['title', 'due_at', 'instructions']);
        $this->assertDatabaseCount('assignments', 0);
        $this->withSession(['studio_workspace' => 'draft'])->post($url, ['title' => 'Wrong workspace', 'lesson_id' => $lesson->id])->assertSessionHasErrors('lesson_id');
    }

    private function lesson(Unit $unit): Lesson
    {
        $key = fake()->uuid();

        return Lesson::create(['manifest_id' => $key, 'version' => '1.0.0', 'title' => 'Leaves', 'sha256' => hash('sha256', $key), 'directory' => 'imports/'.$key, 'report' => [], 'unit_id' => $unit->id]);
    }
}
