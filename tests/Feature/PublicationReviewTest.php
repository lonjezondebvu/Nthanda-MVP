<?php

namespace Tests\Feature;

use App\Models\ContentSetting;
use App\Models\Lesson;
use App\Models\LessonPublicationEvent;
use App\Models\Unit;
use App\Models\User;
use App\Services\PublicationReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use LogicException;
use Tests\TestCase;

class PublicationReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_explicitly_reviews_blockers_and_publishes_with_audit_history(): void
    {
        $lesson = $this->lesson();
        $admin = User::factory()->create(['role' => 'admin']);
        $url = '/admin/lessons/'.$lesson->id.'/publication';
        $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Publication')->where('review.blockers.0', 'Curriculum alignment requires review.'));
        $this->post($url, $this->approval($lesson))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(PublicationReview::class)->isCurrent($lesson->fresh()));
        $this->assertDatabaseHas('lesson_publication_events', ['lesson_id' => $lesson->id, 'action' => 'published', 'reviewer_id' => $admin->id]);
        $this->post($url, ['action' => 'revoke', 'note' => 'New syllabus requires a fresh review.'])->assertSessionHasNoErrors();
        $this->assertFalse(app(PublicationReview::class)->isCurrent($lesson->fresh()));
        $this->assertDatabaseCount('lesson_publication_events', 2);
    }

    public function test_publication_rejects_missing_attestations_unresolved_blockers_and_stale_review(): void
    {
        $lesson = $this->lesson();
        $url = '/admin/lessons/'.$lesson->id.'/publication';
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = $this->approval($lesson);
        $this->post($url, [...$data, 'curriculum' => false, 'note' => '', 'resolved_blockers' => []])->assertSessionHasErrors('note');
        $this->post($url, [...$data, 'curriculum' => false])->assertSessionHasErrors('curriculum');
        $this->post($url, [...$data, 'resolved_blockers' => []])->assertSessionHasErrors('resolved_blockers');
        ContentSetting::create(['lesson_id' => $lesson->id, 'kind' => 'section', 'item_id' => 'S1', 'enabled' => true, 'deleted' => false, 'position' => 2]);
        $this->post($url, $data)->assertSessionHasErrors('fingerprint');
        $this->assertDatabaseCount('lesson_publication_events', 0);
    }

    public function test_publication_is_invalidated_by_settings_or_source_changes(): void
    {
        $lesson = $this->lesson();
        $url = '/admin/lessons/'.$lesson->id.'/publication';
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url, $this->approval($lesson))->assertSessionHasNoErrors();
        $lesson->update(['title' => 'Changed learning content']);
        $this->assertFalse(app(PublicationReview::class)->isCurrent($lesson->fresh()));
        $lesson->update(['title' => 'Leaves']);
        ContentSetting::create(['lesson_id' => $lesson->id, 'kind' => 'section', 'item_id' => 'S1', 'enabled' => false, 'deleted' => false]);
        $this->assertFalse(app(PublicationReview::class)->isCurrent($lesson->fresh()));
        ContentSetting::query()->delete();
        Storage::disk('local')->put($lesson->directory.'/source/marking.json', '{"questions":[],"changed":true}');
        $this->assertFalse(app(PublicationReview::class)->isCurrent($lesson->fresh()));
    }

    public function test_incomplete_lesson_and_unauthorized_users_cannot_publish(): void
    {
        $lesson = $this->lesson();
        $url = '/admin/lessons/'.$lesson->id.'/publication';
        $this->get($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'teacher']))->post($url, [])->assertForbidden();
        $lesson->update(['report' => ['missing_files' => ['missing.mp4']]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url, $this->approval($lesson))->assertSessionHasErrors('publication');
        $lesson->update(['report' => ['missing_files' => []], 'unit_id' => null]);
        $this->post($url, $this->approval($lesson))->assertSessionHasErrors('publication');
        $lesson->update(['unit_id' => Unit::factory()->create()->id]);
        ContentSetting::create(['lesson_id' => $lesson->id, 'kind' => 'section', 'item_id' => 'S1', 'enabled' => false, 'deleted' => false]);
        $this->post($url, $this->approval($lesson))->assertSessionHasErrors('publication');
        $this->assertDatabaseCount('lesson_publication_events', 0);
    }

    public function test_recorded_publication_decisions_cannot_be_rewritten(): void
    {
        $lesson = $this->lesson();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/lessons/'.$lesson->id.'/publication', $this->approval($lesson))->assertSessionHasNoErrors();
        $event = LessonPublicationEvent::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $event->update(['note' => 'Altered after the decision']);
    }

    public function test_admin_can_revoke_approval_when_the_source_package_is_missing(): void
    {
        $lesson = $this->lesson();
        $url = '/admin/lessons/'.$lesson->id.'/publication';
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url, $this->approval($lesson))->assertSessionHasNoErrors();
        $reviewedFingerprint = $lesson->fresh()->publication_review['fingerprint'];
        Storage::disk('local')->delete($lesson->directory.'/derived/lesson.json');
        $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('review.current', false)->where('review.active_count', 0));
        $this->post($url, ['action' => 'revoke', 'note' => 'The source package is unavailable and must be repaired.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($lesson->fresh()->published_at);
        $this->assertNull($lesson->fresh()->publication_review);
        $event = LessonPublicationEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('revoked', $event->action);
        $this->assertSame($reviewedFingerprint, $event->fingerprint);
        $this->assertSame('previous_approval', $event->snapshot['fingerprint_source']);
    }

    private function approval(Lesson $lesson): array
    {
        return ['action' => 'publish', 'note' => 'Verified against approved curriculum and reviewed all learning media.', 'curriculum' => true, 'media' => true, 'accessibility' => true, 'assessment' => true, 'fingerprint' => app(PublicationReview::class)->fingerprint($lesson), 'resolved_blockers' => ['Curriculum alignment requires review.']];
    }

    private function lesson(): Lesson
    {
        Storage::fake('local');
        $lesson = Lesson::create(['manifest_id' => 'PUBLICATION', 'version' => '1.0', 'title' => 'Leaves', 'sha256' => hash('sha256', 'test'), 'directory' => 'imports/publication', 'unit_id' => Unit::factory()->create()->id, 'report' => ['missing_files' => []]]);
        Storage::disk('local')->put($lesson->directory.'/derived/lesson.json', json_encode(['manifest' => ['publication' => ['status' => 'preview-only', 'blockers' => ['Curriculum alignment requires review.']], 'syllabus' => [], 'alternative_explanations' => [], 'assessments' => ['protected_marking_reference' => 'marking.json', 'automatic_policy' => [], 'original_written_policy' => []]], 'sections' => [['id' => 'S1', 'title' => 'Leaves', 'order' => 1, 'criterion_ids' => [], 'blocks' => []]], 'media' => [], 'assets' => [], 'compatibility' => ['activities' => [], 'overview' => []], 'written' => []]));
        Storage::disk('local')->put($lesson->directory.'/source/marking.json', '{"questions":[]}');

        return $lesson;
    }
}
