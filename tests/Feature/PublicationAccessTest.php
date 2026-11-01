<?php

namespace Tests\Feature;

use App\Models\ContentSetting;
use App\Models\Lesson;
use App\Models\PortfolioEntry;
use App\Models\Unit;
use App\Models\User;
use App\Services\LessonAssembler;
use App\Services\PublicationReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_learners_only_access_current_approved_lessons(): void
    {
        config(['learning.allow_preview' => false]);
        $lesson = $this->lesson();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $lesson->unit->subject->school_class_id]);
        $this->actingAs($learner)->get('/lessons/'.$lesson->id)->assertForbidden();
        $this->assertCount(0, Lesson::query()->visibleTo($learner)->get());
        $lesson->update(['published_at' => now(), 'publication_review' => ['fingerprint' => app(PublicationReview::class)->fingerprint($lesson)]]);
        $this->get('/lessons/'.$lesson->id)->assertOk();
        $this->assertSame('published', app(LessonAssembler::class)->assemble($lesson)['publication']['status']);
        $this->assertCount(1, Lesson::query()->visibleTo($learner)->get());
        ContentSetting::create(['lesson_id' => $lesson->id, 'kind' => 'section', 'item_id' => 'S1', 'enabled' => true, 'position' => 2]);
        $this->get('/lessons/'.$lesson->id)->assertForbidden();
        $this->assertCount(0, Lesson::query()->visibleTo($learner)->get());
    }

    public function test_imported_published_label_does_not_bypass_review_and_preview_remains_explicit(): void
    {
        $lesson = $this->lesson();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $lesson->unit->subject->school_class_id]);
        config(['learning.allow_preview' => true]);
        $this->actingAs($learner)->get('/lessons/'.$lesson->id)->assertOk();
        $this->assertSame('preview-only', app(LessonAssembler::class)->assemble($lesson)['publication']['status']);
        config(['learning.allow_preview' => false]);
        $this->getJson('/offline-api/lessons/'.$lesson->id)->assertForbidden();
    }

    public function test_portfolio_evidence_prevents_lesson_deletion(): void
    {
        $lesson = $this->lesson();
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $lesson->unit->subject->school_class_id]);
        PortfolioEntry::factory()->create(['lesson_id' => $lesson->id, 'user_id' => $learner->id, 'school_class_id' => $learner->school_class_id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->delete('/admin/lessons/'.$lesson->id)->assertSessionHasErrors('lesson');
        $this->assertModelExists($lesson);
        $this->assertDatabaseCount('portfolio_entries', 1);
    }

    private function lesson(): Lesson
    {
        Storage::fake('local');
        $lesson = Lesson::create(['manifest_id' => 'ACCESS', 'version' => '1.0', 'title' => 'Leaves', 'sha256' => hash('sha256', 'test'), 'directory' => 'imports/access', 'unit_id' => Unit::factory()->create()->id, 'report' => ['missing_files' => []]]);
        Storage::disk('local')->put($lesson->directory.'/derived/lesson.json', json_encode(['manifest' => ['publication' => ['status' => 'published', 'blockers' => []], 'syllabus' => [], 'alternative_explanations' => [], 'assessments' => ['protected_marking_reference' => 'marking.json', 'automatic_policy' => '', 'original_written_policy' => '']], 'sections' => [['id' => 'S1', 'title' => 'Leaves', 'order' => 1, 'criterion_ids' => [], 'blocks' => []]], 'media' => [], 'assets' => [], 'compatibility' => ['activities' => [], 'overview' => []], 'written' => []]));
        Storage::disk('local')->put($lesson->directory.'/source/marking.json', '{"questions":[]}');

        return $lesson;
    }
}
