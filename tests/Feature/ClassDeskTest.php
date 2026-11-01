<?php

namespace Tests\Feature;

use App\Models\AssessmentAttempt;
use App\Models\Lesson;
use App\Models\ProgressRecord;
use App\Models\SchoolClass;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ClassDeskTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_reviews_written_work_for_their_class_only(): void
    {
        $unit = Unit::factory()->create(['title' => 'Plants']);
        $class = $unit->subject->schoolClass;
        $other = Unit::factory()->create(['title' => 'Rivers']);
        $lesson = $this->lesson($unit, 'Leaves');
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $class->id, 'name' => 'Amina Banda']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->sync([$class->id]);
        $outsider = User::factory()->create(['role' => 'teacher']);
        $outsider->taughtClasses()->sync([$other->subject->school_class_id]);
        ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => 'S1', 'read' => true]);
        AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'automatic', 'status' => 'marked', 'responses' => ['Q1' => 'leaf'], 'result' => [['criterion_id' => 'C1', 'score' => 1, 'max_marks' => 1, 'answered' => 1, 'required' => 1, 'passed' => true]]]);
        $written = AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'written', 'status' => 'pending', 'responses' => ['response' => 'The leaf makes food.']]);

        $this->actingAs($teacher)->get('/teach')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Teach')
            ->has('classes', 1)
            ->where('classes.0.pending', 1));
        $this->get('/teach/classes/'.$class->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('students.0.name', 'Amina Banda')
            ->where('students.0.lessons.0.sections_read', 1)
            ->where('students.0.lessons.0.automatic', 'achieved')
            ->where('students.0.lessons.0.written.0.response', 'The leaf makes food.'));
        $this->patch('/teach/attempts/'.$written->id, ['decision' => 'accepted', 'comment' => 'Clear and complete.'])->assertRedirect()->assertSessionHasNoErrors();
        $written->refresh();
        $this->assertSame('accepted', $written->status);
        $this->assertSame('Clear and complete.', $written->result['comment']);
        $this->assertSame($teacher->id, $written->reviewer_id);
        $this->get('/teach')->assertInertia(fn (AssertableInertia $page) => $page->where('classes.0.pending', 0));

        $this->actingAs($outsider)->patch('/teach/attempts/'.$written->id, ['decision' => 'needs_another_try', 'comment' => 'Try again.'])->assertForbidden();
        $this->get('/teach/classes/'.$class->id)->assertForbidden();
        $this->post('/lessons/'.$lesson->id.'/progress', ['section_id' => 'S1', 'read' => true])->assertForbidden();
        $this->actingAs($learner)->get('/teach')->assertForbidden();
        $this->post('/admin/classes', ['name' => 'Form 9'])->assertForbidden();
    }

    public function test_an_administrator_creates_and_updates_a_teacher(): void
    {
        $class = SchoolClass::factory()->create(['name' => 'Form 2']);
        $next = SchoolClass::factory()->create(['name' => 'Form 3']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/teachers', [
            'name' => 'Chisomo Phiri',
            'email' => 'chisomo@example.com',
            'password' => 'password1',
            'classes' => [$class->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $teacher = User::query()->where('email', 'chisomo@example.com')->firstOrFail();
        $this->assertSame('teacher', $teacher->role);
        $this->assertEqualsCanonicalizing([$class->id], $teacher->taughtClasses()->pluck('school_classes.id')->all());

        $this->patch('/admin/teachers/'.$teacher->id, [
            'name' => 'Chisomo Banda',
            'classes' => [$next->id],
            'deactivated' => true,
            'password' => null,
        ])->assertSessionHasNoErrors();
        $teacher->refresh();
        $this->assertSame('Chisomo Banda', $teacher->name);
        $this->assertNotNull($teacher->deactivated_at);
        $this->assertEqualsCanonicalizing([$next->id], $teacher->taughtClasses()->pluck('school_classes.id')->all());
    }

    public function test_review_queue_filters_students_and_uses_latest_automatic_attempt(): void
    {
        $unit = Unit::factory()->create();
        $lesson = $this->lesson($unit, 'Queue');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($unit->subject->school_class_id);
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id, 'name' => 'Amina']);
        User::factory()->create(['role' => 'learner', 'school_class_id' => $learner->school_class_id, 'name' => 'Banda']);
        $base = ['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'responses' => [], 'created_at' => now()];
        AssessmentAttempt::create([...$base, 'kind' => 'automatic', 'status' => 'marked', 'result' => [['passed' => true]]]);
        AssessmentAttempt::create([...$base, 'kind' => 'automatic', 'status' => 'marked', 'result' => [['passed' => false]]]);
        AssessmentAttempt::create([...$base, 'kind' => 'written', 'status' => 'pending', 'responses' => ['response' => 'Please review.']]);
        $this->actingAs($teacher)->get('/teach/classes/'.$learner->school_class_id.'?pending=1')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('students', 1)->where('students.0.name', 'Amina')->where('students.0.pending', 1)
            ->where('students.0.lessons.0.automatic', 'not_yet')->where('summary.students', 2)->where('summary.pending', 1));
        $this->get('/teach/classes/'.$learner->school_class_id.'?search=Banda')->assertInertia(fn (AssertableInertia $page) => $page->has('students', 1)->where('students.0.name', 'Banda'));
        $this->get('/teach/classes/'.$learner->school_class_id.'?search=Missing')->assertInertia(fn (AssertableInertia $page) => $page->has('students', 0)->where('selectedStudent', null));
    }

    public function test_review_queue_paginates_learners_and_written_history(): void
    {
        $unit = Unit::factory()->create();
        $lesson = $this->lesson($unit, 'Bounded history');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($unit->subject->school_class_id);
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id, 'name' => 'A first learner']);
        User::factory()->count(21)->create(['role' => 'learner', 'school_class_id' => $learner->school_class_id, 'name' => 'Z learner']);
        foreach (range(1, 21) as $index) {
            AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'written', 'status' => 'pending', 'responses' => ['response' => 'Response '.$index]]);
        }
        $url = '/teach/classes/'.$learner->school_class_id;
        $this->actingAs($teacher)->get($url)->assertInertia(fn (AssertableInertia $page) => $page->has('students', 20)->where('pagination.total', 22)->where('workPagination.total', 21)->has('students.0.lessons.0.written', 20));
        $this->get($url.'?work_page=2')->assertInertia(fn (AssertableInertia $page) => $page->has('students.0.lessons.0.written', 1));
        $this->get($url.'?page=2')->assertInertia(fn (AssertableInertia $page) => $page->has('students', 2));
    }

    public function test_last_server_activity_uses_only_the_selected_learners_current_class_lessons(): void
    {
        $this->travelTo(now()->startOfSecond());
        $unit = Unit::factory()->create();
        $lesson = $this->lesson($unit, 'Activity');
        $otherLesson = $this->lesson(Unit::factory()->create(), 'Other class activity');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($unit->subject->school_class_id);
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id, 'name' => 'Amina']);
        $otherLearner = User::factory()->create(['role' => 'learner', 'school_class_id' => $learner->school_class_id, 'name' => 'Banda']);
        $url = '/teach/classes/'.$learner->school_class_id;
        $this->actingAs($teacher)->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('lastServerActivity', null));
        $savedAt = now()->subHours(2);
        ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => 'S1', 'read' => false, 'confidence' => 2, 'updated_at' => $savedAt]);
        ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $otherLesson->id, 'section_id' => 'S1', 'read' => true, 'updated_at' => now()]);
        AssessmentAttempt::create(['user_id' => $otherLearner->id, 'lesson_id' => $lesson->id, 'kind' => 'written', 'status' => 'pending', 'responses' => [], 'created_at' => now()]);
        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('lastServerActivity', $savedAt->toIso8601String()));
        $attemptAt = now()->subHour();
        AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'automatic', 'status' => 'marked', 'responses' => [], 'created_at' => $attemptAt]);
        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('lastServerActivity', $attemptAt->toIso8601String()));
        $this->travelBack();
    }

    private function lesson(Unit $unit, string $title): Lesson
    {
        return Lesson::create([
            'manifest_id' => 'DESK-'.$title,
            'version' => '1.0.0',
            'title' => $title,
            'sha256' => hash('sha256', $title),
            'directory' => 'imports/'.$title,
            'report' => [],
            'unit_id' => $unit->id,
        ]);
    }
}
