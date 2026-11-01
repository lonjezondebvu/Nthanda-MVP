<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\ProgressRecord;
use App\Models\SchoolClass;
use App\Models\Unit;
use App\Models\User;
use App\Services\PackageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_register_with_a_class_and_disabilities(): void
    {
        $class = SchoolClass::factory()->create(['name' => 'Form 2', 'position' => 1]);

        $this->post('/register', [
            'name' => 'Amina Banda',
            'email' => 'amina@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'school_class_id' => $class->id,
            'disabilities' => ['seeing', 'learning'],
        ])->assertRedirect('/lessons');

        $this->assertAuthenticated();
        $user = User::query()->where('email', 'amina@example.com')->firstOrFail();
        $this->assertSame('learner', $user->role);
        $this->assertSame($class->id, $user->school_class_id);
        $this->assertSame(['seeing', 'learning'], $user->disabilities);
        $this->assertNull($user->disability_note);
    }

    public function test_registration_rejects_mixed_disclosure_and_other_without_a_note(): void
    {
        $class = SchoolClass::factory()->create();

        $this->post('/register', [
            'name' => 'Amina Banda',
            'email' => 'amina@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'school_class_id' => $class->id,
            'disabilities' => ['none', 'seeing'],
        ])->assertSessionHasErrors('disabilities');

        $this->post('/register', [
            'name' => 'Amina Banda',
            'email' => 'amina@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'school_class_id' => $class->id,
            'disabilities' => ['other'],
        ])->assertSessionHasErrors('disability_note');
        $this->assertGuest();
    }

    public function test_students_update_their_profile_and_password(): void
    {
        $class = SchoolClass::factory()->create();
        $next = SchoolClass::factory()->create();
        $user = User::factory()->create(['role' => 'learner', 'school_class_id' => $class->id, 'disabilities' => ['hearing']]);

        $this->actingAs($user)->patch('/account', [
            'name' => 'Amina Banda',
            'school_class_id' => $next->id,
            'disabilities' => ['other'],
            'disability_note' => 'Needs large print',
            'current_password' => 'password',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Amina Banda', $user->name);
        $this->assertSame($next->id, $user->school_class_id);
        $this->assertSame(['other'], $user->disabilities);
        $this->assertSame('Needs large print', $user->disability_note);
        $this->assertTrue(Hash::check('newpassword', $user->password));
    }

    public function test_administrators_manage_the_catalogue_and_students(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'learner']);
        $this->actingAs($admin);

        $this->post('/admin/classes', ['name' => 'Form 1'])->assertRedirect()->assertSessionHasNoErrors();
        $class = SchoolClass::query()->where('name', 'Form 1')->firstOrFail();
        $this->post('/admin/classes/'.$class->id.'/subjects', ['name' => 'Biology'])->assertSessionHasNoErrors();
        $subject = $class->subjects()->firstOrFail();
        $this->post('/admin/subjects/'.$subject->id.'/units', ['title' => 'Plants'])->assertSessionHasNoErrors();
        $unit = $subject->units()->firstOrFail();
        $this->patch('/admin/classes/'.$class->id, ['name' => 'Form 1A'])->assertSessionHasNoErrors();
        $this->patch('/admin/subjects/'.$subject->id, ['name' => 'Life science'])->assertSessionHasNoErrors();
        $this->patch('/admin/units/'.$unit->id, ['title' => 'Plant systems'])->assertSessionHasNoErrors();
        $this->delete('/admin/classes/'.$class->id)->assertSessionHasErrors('catalogue');
        $this->delete('/admin/units/'.$unit->id)->assertSessionHasNoErrors();
        $this->delete('/admin/subjects/'.$subject->id)->assertSessionHasNoErrors();
        $this->delete('/admin/classes/'.$class->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('school_classes', 0);

        $kept = SchoolClass::factory()->create(['name' => 'Form 3']);
        $moved = SchoolClass::factory()->create(['name' => 'Form 4']);
        $this->patch('/admin/students/'.$student->id, ['school_class_id' => $moved->id, 'deactivated' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($student->refresh()->deactivated_at);
        $this->assertSame($moved->id, $student->school_class_id);
        $this->post('/logout');
        $this->post('/login', ['email' => $student->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNotNull($kept->id);
    }

    public function test_learners_only_open_lessons_placed_in_their_class(): void
    {
        Storage::fake('local');
        $lesson = $this->importLesson('CLASS-ONE', 'Leaf structure');
        $hidden = $this->importLesson('CLASS-TWO', 'River systems');
        $unit = Unit::factory()->create(['title' => 'Plants']);
        $other = Unit::factory()->create(['title' => 'Water']);
        $lesson->update(['unit_id' => $unit->id]);
        $hidden->update(['unit_id' => $other->id]);
        $learner = User::factory()->create(['role' => 'learner', 'school_class_id' => $unit->subject->school_class_id]);

        $this->actingAs($learner)->get('/lessons')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('lessons', 1)
            ->where('lessons.0.id', $lesson->id)
            ->has('catalogue', 1)
            ->where('catalogue.0.subjects.0.units.0.lessons.0.id', $lesson->id)
            ->where('needsClass', false));
        $this->get('/lessons/'.$hidden->id)->assertForbidden();
        $this->get('/lessons/'.$lesson->id)->assertOk();
        $this->post('/admin/classes', ['name' => 'Form 9'])->assertForbidden();
    }

    public function test_unassigned_learners_and_unplaced_lessons_stay_hidden(): void
    {
        Storage::fake('local');
        $lesson = $this->importLesson('UNPLACED', 'Draft lesson');
        $learner = User::factory()->create(['role' => 'learner']);

        $this->actingAs($learner)->get('/lessons')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('lessons', 0)
            ->where('needsClass', true));
        $this->get('/lessons/'.$lesson->id)->assertForbidden();
        $this->getJson('/offline-api/lessons/'.$lesson->id)->assertForbidden();
    }

    public function test_packages_with_learner_progress_cannot_be_deleted(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $lesson = $this->importLesson('DELETE-ME', 'Temporary lesson');
        $unit = Unit::factory()->create();
        $directory = $lesson->directory;

        $this->actingAs($admin)->patch('/admin/lessons/'.$lesson->id.'/placement', ['title' => 'Filed lesson', 'unit_id' => $unit->id])->assertSessionHasNoErrors();
        $this->assertSame($unit->id, $lesson->refresh()->unit_id);
        $this->assertSame('Filed lesson', $lesson->title);
        ProgressRecord::create(['user_id' => $admin->id, 'lesson_id' => $lesson->id, 'section_id' => 'S1', 'read' => true]);
        $this->delete('/admin/lessons/'.$lesson->id)->assertSessionHasErrors('lesson');
        $this->assertModelExists($lesson);
        ProgressRecord::query()->delete();
        $this->delete('/admin/lessons/'.$lesson->id)->assertRedirect('/admin/lessons');
        $this->assertModelMissing($lesson);
        Storage::disk('local')->assertMissing($directory.'/derived/lesson.json');
    }

    private function importLesson(string $id, string $title): Lesson
    {
        $path = tempnam(sys_get_temp_dir(), 'nthanda-catalogue-');
        $manifest = [
            'schema_version' => 'nthanda-pilot-2.0', 'lesson_id' => $id, 'lesson_version' => '1.0.0', 'title' => $title, 'language' => 'en',
            'source' => ['path' => 'source.txt', 'version' => '1', 'sha256' => hash('sha256', 'Catalogue source')],
            'syllabus' => ['curriculum_id' => 'TEST', 'edition' => '2026', 'subject' => 'Biology', 'form' => 1, 'printed_pages' => '1'],
            'tracking' => ['learner_timer_visible' => false, 'sections' => true, 'lesson_total' => true, 'storage' => 'local', 'idle_pause_seconds' => 60],
            'sections' => [['id' => 'S1', 'order' => 1, 'title' => 'Opening', 'content_path' => 'content/one.json', 'criterion_ids' => ['C1'], 'block_ids' => ['B1']]],
            'overview' => ['summary' => 'Imported test overview'], 'alternative_explanations' => [],
            'assessments' => ['learner_written_path' => 'written.json', 'protected_marking_reference' => 'assessments/marking.json', 'protected_source_rubric_reference' => 'assessments/rubric.json', 'automatic_policy' => 'Automatic checks', 'original_written_policy' => 'Teacher review'],
            'teaching_links' => [], 'assessment_links' => [],
            'media' => [],
            'review' => ['status' => 'pending', 'reviewer' => null, 'approved_source_version' => null], 'publication' => ['status' => 'preview', 'blockers' => []],
        ];
        $files = [
            'lesson-manifest.json' => $manifest,
            'dist/content/one.json' => ['id' => 'S1', 'blocks' => [['id' => 'B1', 'type' => 'paragraph', 'order' => 1, 'text' => 'Lesson text.']]],
            'dist/written.json' => ['text' => ['Describe it.']],
            'assessments/marking.json' => ['questions' => [['id' => 'Q1', 'criterionId' => 'C1', 'kind' => 'short', 'domain' => 'knowledge', 'prompt' => 'Name it.', 'answer' => ['leaf'], 'sectionIds' => ['S1'], 'maxMarks' => 1]], 'rules' => [['criterion_id' => 'C1', 'required_question_ids' => ['Q1'], 'max_marks' => 1, 'minimum_overall_ratio' => 1]]],
            'assessments/rubric.json' => [],
        ];
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $data) {
            $zip->addFromString($name, json_encode($data));
        }
        $zip->addFromString('source.txt', 'Catalogue source');
        $zip->close();
        try {
            return app(PackageImporter::class)->import($path)['lesson'];
        } finally {
            unlink($path);
        }
    }
}
