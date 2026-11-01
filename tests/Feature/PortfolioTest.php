<?php

namespace Tests\Feature;

use App\Models\PortfolioEntry;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_learner_submits_private_work_with_author_and_class_assigned_by_server(): void
    {
        Storage::fake('local');
        $sample = PortfolioEntry::factory()->create();
        $user = $sample->learner;
        $this->actingAs($user)->post('/portfolio', ['lesson_id' => $sample->lesson_id, 'title' => 'My observation', 'reflection' => 'I found leaves growing towards the light.', 'attachment' => UploadedFile::fake()->createWithContent('observation.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj"), 'user_id' => 999, 'status' => 'accepted'])->assertSessionHasNoErrors()->assertRedirect();
        $entry = PortfolioEntry::latest('id')->firstOrFail();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('submitted', $entry->status);
        Storage::disk('local')->assertExists($entry->attachment_path);
        $this->get('/portfolio/'.$entry->id.'/attachment')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_spoofed_and_oversized_attachments_and_foreign_lessons_are_rejected(): void
    {
        Storage::fake('local');
        $entry = PortfolioEntry::factory()->create();
        $other = PortfolioEntry::factory()->create();
        $data = ['lesson_id' => $entry->lesson_id, 'title' => 'Observation', 'reflection' => 'A reflection'];
        $spoof = UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "unsafe";');
        $spoofedUpload = new UploadedFile($spoof->getPathname(), 'photo.jpg', 'image/jpeg', null, true);
        $this->actingAs($entry->learner)->post('/portfolio', [...$data, 'attachment' => $spoofedUpload])->assertSessionHasErrors('attachment');
        $this->post('/portfolio', [...$data, 'attachment' => UploadedFile::fake()->create('photo.jpg', 10241, 'image/jpeg')])->assertSessionHasErrors('attachment');
        $this->post('/portfolio', [...$data, 'lesson_id' => $other->lesson_id])->assertNotFound();
        $disguisedPdf = UploadedFile::fake()->create('photo.jpg', 10, 'application/pdf');
        $this->post('/portfolio', [...$data, 'attachment' => $disguisedPdf])->assertSessionHasErrors('attachment');
        $this->assertDatabaseCount('portfolio_entries', 2);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_current_class_teacher_can_review_and_learners_cannot_see_each_others_entries(): void
    {
        $entry = PortfolioEntry::factory()->create();
        $peer = User::factory()->create(['school_class_id' => $entry->school_class_id]);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($entry->school_class_id);
        $this->actingAs($peer)->get('/portfolio')->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 0));
        $this->get('/portfolio/'.$entry->id.'/attachment')->assertNotFound();
        $this->patch('/portfolio/'.$entry->id.'/review', ['status' => 'accepted', 'feedback' => 'Good work'])->assertForbidden();
        $this->actingAs($teacher)->patch('/portfolio/'.$entry->id.'/review', ['status' => 'accepted', 'feedback' => 'Good observation'])->assertSessionHasNoErrors();
        $this->assertSame($teacher->id, $entry->fresh()->reviewer_id);
        $this->assertSame('accepted', $entry->fresh()->status);
        $this->assertNotNull($entry->fresh()->reviewed_at);
        $this->patch('/portfolio/'.$entry->id.'/review', ['status' => 'made_up', 'feedback' => ''])->assertSessionHasErrors(['status', 'feedback']);
        $outsider = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($outsider)->patch('/portfolio/'.$entry->id.'/review', ['status' => 'accepted', 'feedback' => 'No access'])->assertNotFound();
    }

    public function test_workspace_and_moved_learners_remove_access_to_entries_and_attachments(): void
    {
        Storage::fake('local');
        $entry = PortfolioEntry::factory()->create(['attachment_path' => 'portfolio/proof.pdf', 'attachment_name' => 'proof.pdf']);
        Storage::disk('local')->put($entry->attachment_path, '%PDF-1.4');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($entry->school_class_id);
        $this->actingAs($teacher)->withSession(['studio_workspace' => 'trial'])->get('/portfolio/'.$entry->id.'/attachment')->assertNotFound();
        $this->withSession(['studio_workspace' => 'main']);
        $entry->learner->update(['school_class_id' => SchoolClass::factory()->create()->id]);
        $this->get('/portfolio')->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 0));
        $this->get('/portfolio/'.$entry->id.'/attachment')->assertNotFound();
        $this->patch('/portfolio/'.$entry->id.'/review', ['status' => 'accepted', 'feedback' => 'No longer my class'])->assertNotFound();
    }

    public function test_moving_a_lesson_or_revoking_teacher_access_hides_existing_work(): void
    {
        $entry = PortfolioEntry::factory()->create();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $teacher->taughtClasses()->attach($entry->school_class_id);
        $this->actingAs($teacher)->get('/portfolio')->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 1));
        $teacher->taughtClasses()->detach();
        $this->get('/portfolio')->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 0));
        $teacher->taughtClasses()->attach($entry->school_class_id);
        $entry->lesson->unit->subject->update(['school_class_id' => SchoolClass::factory()->create()->id]);
        $this->actingAs($entry->learner)->get('/portfolio')->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 0));
        $this->actingAs($teacher)->patch('/portfolio/'.$entry->id.'/review', ['status' => 'accepted', 'feedback' => 'Moved lesson'])->assertNotFound();
    }
}
