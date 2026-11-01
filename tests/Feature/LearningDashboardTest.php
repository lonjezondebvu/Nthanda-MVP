<?php

namespace Tests\Feature;

use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\Lesson;
use App\Models\ProgressRecord;
use App\Models\Unit;
use App\Models\User;
use App\Services\LessonAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LearningDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_must_sign_in_and_unassigned_learners_have_an_empty_dashboard(): void
    {
        $this->get('/today')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'learner', 'school_class_id' => null]))
            ->get('/today')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Today')->where('needsClass', true)->where('continueLearning', null)->has('mastery', 0));
    }

    public function test_latest_assessment_is_evidence_and_reading_does_not_award_mastery(): void
    {
        $unit = Unit::factory()->create();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id]);
        $lesson = $this->lesson($unit, 'Leaves');
        $this->mockContent($lesson);
        ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => 'S1', 'read' => true, 'confidence' => 3]);
        foreach ([true, false] as $passed) {
            AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'automatic', 'status' => 'marked', 'responses' => ['secret' => 'private'], 'created_at' => '2026-01-01 12:00:00', 'result' => [['criterion_id' => 'C1', 'passed' => $passed, 'score' => $passed ? 2 : 0, 'max_marks' => 2]]]);
        }

        $response = $this->actingAs($learner)->get('/today')->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Today')
            ->where('continueLearning.section_title', 'Roots')->where('summary.sections_read', 1)
            ->where('mastery.0.criteria.0.status', 'needs_practice')->where('mastery.0.criteria.0.evidence.score', 0)
            ->where('mastery.0.criteria.1.status', 'not_assessed')->where('recommendations.0.reason', 'assessment')
            ->missing('mastery.0.criteria.0.evidence.responses'));
        $response->assertDontSee('private');
    }

    public function test_hidden_classes_other_learners_and_unavailable_sections_do_not_leak(): void
    {
        $unit = Unit::factory()->create();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id]);
        $other = User::factory()->create();
        $lesson = $this->lesson($unit, 'Leaves');
        $hidden = $this->lesson(Unit::factory()->create(), 'Private lesson');
        $this->mockContent($lesson);
        ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => 'deleted-section', 'read' => true, 'confidence' => 1]);
        ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => 'S2', 'confidence' => 1]);
        foreach ([[$other->id, $lesson->id], [$learner->id, $hidden->id]] as [$userId, $lessonId]) {
            AssessmentAttempt::create(['user_id' => $userId, 'lesson_id' => $lessonId, 'kind' => 'written', 'status' => 'accepted', 'responses' => [], 'result' => ['comment' => 'Hidden feedback']]);
        }
        AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'written', 'status' => 'needs_another_try', 'responses' => [], 'result' => ['comment' => 'Explain your observation.'], 'reviewed_at' => now()]);

        $this->actingAs($learner)->get('/today')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('mastery', 1)->where('summary.sections_read', 0)->has('feedback', 1)
            ->where('feedback.0.comment', 'Explain your observation.')->has('recommendations', 1)
            ->where('recommendations.0.section_title', 'Roots')->where('recommendations.0.reason', 'confidence'));
    }

    public function test_a_passed_latest_assessment_records_mastery_without_claiming_reading(): void
    {
        $unit = Unit::factory()->create();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id]);
        $lesson = $this->lesson($unit, 'Leaves');
        $this->mockContent($lesson);
        AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'automatic', 'status' => 'marked', 'responses' => [], 'result' => [['criterion_id' => 'C1', 'passed' => true, 'score' => 2, 'max_marks' => 2]]]);

        $this->actingAs($learner)->get('/today')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.demonstrated', 1)->where('summary.sections_read', 0)
            ->where('mastery.0.criteria.0.status', 'demonstrated')->has('recommendations', 0));
    }

    public function test_shows_only_active_assignments_for_the_learners_current_class(): void
    {
        $unit = Unit::factory()->create();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id]);
        $lesson = $this->lesson($unit, 'Leaves');
        $this->mockContent($lesson);
        Assignment::factory()->create(['lesson_id' => $lesson->id, 'title' => 'Observe a leaf', 'due_at' => null]);
        Assignment::factory()->create(['lesson_id' => $lesson->id, 'archived_at' => now()]);
        Assignment::factory()->create();

        $this->actingAs($learner)->get('/today')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('assignments', 1)->where('assignments.0.title', 'Observe a leaf')->where('assignments.0.due_at', null));
    }

    public function test_unreadable_packages_do_not_hide_healthy_learning_assignments_or_feedback(): void
    {
        Storage::fake('local');
        $unit = Unit::factory()->create();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id]);
        $healthy = $this->lesson($unit, 'Healthy lesson');
        $missing = $this->lesson($unit, 'Missing package');
        $corrupt = $this->lesson($unit, 'Corrupt package');
        Storage::disk('local')->put($corrupt->directory.'/derived/lesson.json', '{broken json');
        $this->partialMock(LessonAssembler::class, function (MockInterface $mock) use ($healthy): void {
            $mock->shouldReceive('assemble')->withArgs(fn (Lesson $lesson): bool => $lesson->id !== $healthy->id)->passthru();
            $mock->shouldReceive('assemble')->withArgs(fn (Lesson $lesson): bool => $lesson->id === $healthy->id)->andReturn([
                'sections' => [['id' => 'S1', 'title' => 'Healthy section', 'criterion_ids' => []]], 'questions' => [],
            ]);
        });
        Assignment::factory()->create(['lesson_id' => $healthy->id, 'title' => 'Keep learning']);
        AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $healthy->id, 'kind' => 'written', 'status' => 'accepted', 'responses' => [], 'result' => ['comment' => 'Keep this feedback'], 'reviewed_at' => now()]);

        $response = $this->actingAs($learner)->get('/today')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unavailableLessons', 2)->where('continueLearning.section_title', 'Healthy section')->has('mastery', 1)
            ->where('assignments.0.title', 'Keep learning')->where('feedback.0.comment', 'Keep this feedback'));
        $response->assertDontSee($missing->directory)->assertDontSee($corrupt->directory);
    }

    #[DataProvider('spacedReviewCases')]
    public function test_spaced_review_uses_the_latest_assessment_and_section_check_in(int $assessmentDays, int $checkInDays, bool $read, bool $passed, ?int $confidence, array $expectedReasons): void
    {
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0, 0));
        $unit = Unit::factory()->create();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id]);
        $lesson = $this->lesson($unit, 'Leaves');
        $this->mockContent($lesson);
        ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => 'S1', 'read' => $read, 'confidence' => $confidence, 'updated_at' => now()->subDays($checkInDays)]);
        ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => 'hidden-section', 'read' => true, 'updated_at' => now()->subDays(30)]);
        AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'automatic', 'status' => 'marked', 'responses' => [], 'created_at' => now()->subDays($assessmentDays), 'result' => [['criterion_id' => 'C1', 'passed' => $passed, 'score' => $passed ? 2 : 0, 'max_marks' => 2]]]);

        $this->actingAs($learner)->get('/today')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('recommendations', fn (Collection $items): bool => $items->pluck('reason')->all() === $expectedReasons)
            ->where('recommendations', fn (Collection $items): bool => $items->every(fn (array $item): bool => $item['section_title'] === 'Leaves')));
    }

    public function test_assessment_support_precedes_due_reviews_and_explains_the_review_date(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0, 0));
        $unit = Unit::factory()->create();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id]);
        $lesson = $this->lesson($unit, 'Leaves');
        $this->mockContent($lesson);
        foreach (['S1', 'S2'] as $sectionId) {
            ProgressRecord::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => $sectionId, 'read' => true, 'updated_at' => now()->subDays(9)]);
        }
        AssessmentAttempt::create(['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'kind' => 'automatic', 'status' => 'marked', 'responses' => [], 'created_at' => now()->subDays(10), 'result' => [
            ['criterion_id' => 'C1', 'passed' => true, 'score' => 2, 'max_marks' => 2],
            ['criterion_id' => 'C2', 'passed' => false, 'score' => 0, 'max_marks' => 2],
        ]]);

        $this->actingAs($learner)->get('/today')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recommendations', 2)->where('recommendations.0.reason', 'assessment')->where('recommendations.0.section_title', 'Roots')
            ->where('recommendations.1.reason', 'spaced_review')->where('recommendations.1.description', 'A seven-day review: you demonstrated a criterion here, and your latest assessment or section check-in was on 2026-11-06. Revisit it to keep practising.'));
    }

    /** @return array<string, array{int, int, bool, bool, ?int, list<string>}> */
    public static function spacedReviewCases(): array
    {
        return [
            'recent assessment is not due' => [6, 10, true, true, null, []],
            'seven day boundary is due' => [7, 10, true, true, null, ['spaced_review']],
            'overdue assessment and reading' => [10, 9, true, true, null, ['spaced_review']],
            'recent check in postpones review' => [10, 1, true, true, null, []],
            'unread sections are not spaced reviews' => [10, 9, false, true, null, []],
            'failed assessment takes priority' => [10, 9, true, false, 1, ['assessment']],
            'confidence support takes priority' => [10, 9, true, true, 1, ['confidence']],
        ];
    }

    private function lesson(Unit $unit, string $title): Lesson
    {
        return Lesson::create(['manifest_id' => $title, 'title' => $title, 'version' => '1', 'sha256' => hash('sha256', $title), 'directory' => 'imports/'.$title, 'unit_id' => $unit->id, 'report' => []]);
    }

    private function mockContent(Lesson $lesson): void
    {
        $this->mock(LessonAssembler::class, function (MockInterface $mock) use ($lesson): void {
            $mock->shouldReceive('assemble')->withArgs(fn (Lesson $actual): bool => $actual->id === $lesson->id)->andReturn([
                'sections' => [
                    ['id' => 'S1', 'title' => 'Leaves', 'criterion_ids' => ['C1']],
                    ['id' => 'S2', 'title' => 'Roots', 'criterion_ids' => ['C2']],
                ],
                'questions' => [['criterionId' => 'C1'], ['criterionId' => 'C2']],
            ]);
        });
    }
}
