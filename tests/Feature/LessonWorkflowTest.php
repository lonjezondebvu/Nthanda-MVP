<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use App\Services\AssessmentMarker;
use App\Services\CurriculumPackages;
use App\Services\LessonAssembler;
use App\Services\PackageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LessonWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function pilotPath(): string
    {
        $path = env('PILOT_ZIP', 'C:/Users/USER/Downloads/Nthanda Plant Pilot.zip');
        if (! is_file($path)) {
            $this->markTestSkipped('Set PILOT_ZIP to the supplied Plant Pilot archive to run real-package integration tests.');
        }

        return $path;
    }

    private function pilot(): Lesson
    {
        Storage::fake('local');

        return app(PackageImporter::class)->import($this->pilotPath())['lesson'];
    }

    public function test_exported_pilot_version_keeps_its_practice_overview_and_media(): void
    {
        $lesson = $this->pilot();
        $assembler = app(LessonAssembler::class);
        $original = $assembler->assemble($lesson);
        $updated = app(CurriculumPackages::class)->createVersion($lesson, ['title' => 'Updated pilot', 'version' => '2.2.0-test', 'sections' => $assembler->source($lesson)['sections']]);

        $data = $assembler->assemble($updated);
        $this->assertSame($original['overview'], $data['overview']);
        $this->assertSame($original['sections'][0]['activities'], $data['sections'][0]['activities']);
        $this->assertCount(count($original['sections'][1]['media']), $data['sections'][1]['media']);
        $this->assertCount(count($original['questions']), $data['questions']);
    }

    public function test_administrator_can_sign_in_upload_and_manage_a_package(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => $admin->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin/lessons');
        $this->assertAuthenticatedAs($admin);
        $file = new UploadedFile($this->pilotPath(), 'Nthanda Plant Pilot.zip', 'application/zip', null, true);
        $this->post('/admin/import', ['package' => $file])->assertRedirect('/admin/lessons/1');
        $this->get('/admin/lessons/1')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Manage')->where('report.sections', 9)->where('lesson.active_count', 9));
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_real_package_imports_nine_sections_and_media_without_exposing_answers(): void
    {
        $lesson = $this->pilot();
        $data = app(LessonAssembler::class)->assemble($lesson);
        $this->assertCount(9, $data['sections']);
        $this->assertCount(32, $data['questions']);
        $this->assertGreaterThan(40, count($data['assets']));
        $this->assertStringNotContainsString('"answer":', json_encode($data));
        $this->assertStringNotContainsString('protected_marking_reference', json_encode($data));
        $this->assertNotEmpty($data['sections'][0]['activities']);
        $this->assertEquals('preview-only', $data['publication']['status']);
        $this->assertNotEmpty($data['overview']['introduction']);
        $this->assertEquals($lesson->sha256, hash_file('sha256', Storage::disk('local')->path($lesson->directory.'/original.zip')));
        foreach ($data['assets'] as $asset) {
            $this->assertFileExists(Storage::disk('local')->path($lesson->directory.'/'.$asset['path']));
        }
        $video = collect($data['assets'])->firstWhere('mime', 'video/mp4');
        $this->assertGreaterThan(47000000, filesize(Storage::disk('local')->path($lesson->directory.'/'.$video['path'])));
        $again = app(PackageImporter::class)->import($this->pilotPath());
        $this->assertTrue($again['duplicate']);
        $this->assertEquals(1, Lesson::count());
    }

    public function test_overrides_preserve_identity_history_and_assessment_thresholds(): void
    {
        $lesson = $this->pilot();
        $admin = User::factory()->create(['role' => 'admin']);
        $learner = User::factory()->create(['role' => 'learner']);
        $this->enroll($learner, $lesson);
        $data = app(LessonAssembler::class)->assemble($lesson);
        $s8 = $data['sections'][7]['id'];
        $this->actingAs($learner)->post('/lessons/'.$lesson->id.'/progress', ['section_id' => $s8, 'read' => true, 'confidence' => 2])->assertRedirect();
        $this->actingAs($admin)->patch('/admin/lessons/'.$lesson->id.'/settings', ['kind' => 'section', 'item_id' => $s8, 'enabled' => false])->assertRedirect();
        $this->assertCount(8, app(LessonAssembler::class)->assemble($lesson)['sections']);
        $this->actingAs($learner)->get('/lessons/'.$lesson->id.'/sections/'.$s8)->assertOk()->assertSee('unavailable');
        $this->actingAs($admin)->patch('/admin/lessons/'.$lesson->id.'/settings', ['kind' => 'section', 'item_id' => $s8, 'enabled' => true])->assertRedirect();
        $this->assertCount(9, app(LessonAssembler::class)->assemble($lesson)['sections']);
        $ids = array_reverse(array_column($data['sections'], 'id'));
        $this->post('/admin/lessons/'.$lesson->id.'/order', ['ids' => $ids])->assertRedirect();
        $this->assertSame($ids, array_column(app(LessonAssembler::class)->assemble($lesson)['sections'], 'id'));
        $this->assertDatabaseHas('progress_records', ['user_id' => $learner->id, 'lesson_id' => $lesson->id, 'section_id' => $s8, 'read' => true, 'confidence' => 2]);
        $block = $data['sections'][0]['blocks'][0]['id'];
        $this->patch('/admin/lessons/'.$lesson->id.'/settings', ['kind' => 'block', 'item_id' => $block, 'enabled' => false])->assertRedirect();
        $this->assertCount(0, collect(app(LessonAssembler::class)->assemble($lesson)['sections'])->firstWhere('id', $data['sections'][0]['id'])['blocks']);
        $this->patch('/admin/lessons/'.$lesson->id.'/settings', ['kind' => 'media', 'item_id' => 'image1', 'enabled' => false])->assertRedirect();
        $this->get('/lessons/'.$lesson->id.'/assets/'.hash('sha256', 'dist/media/images/L01_I01_Plant_Parts.svg'))->assertNotFound();
        $this->assertCount(32, app(LessonAssembler::class)->assemble($lesson)['questions']);
        $this->assertNotEmpty(app(LessonAssembler::class)->assemble($lesson, true)['review_flags']);
        $this->patch('/admin/lessons/'.$lesson->id.'/settings', ['kind' => 'section', 'item_id' => $s8, 'deleted' => true])->assertRedirect();
        $this->assertCount(8, app(LessonAssembler::class)->assemble($lesson)['sections']);
        $this->patch('/admin/lessons/'.$lesson->id.'/settings', ['kind' => 'section', 'item_id' => $s8, 'deleted' => false])->assertRedirect();
        $this->assertCount(9, app(LessonAssembler::class)->assemble($lesson)['sections']);
    }

    public function test_server_authorization_protected_assets_and_marking(): void
    {
        $lesson = $this->pilot();
        $this->get('/admin/lessons')->assertRedirect('/login');
        $user = User::factory()->create(['role' => 'learner']);
        $this->enroll($user, $lesson);
        $this->actingAs($user)->get('/admin/lessons')->assertForbidden();
        $this->post('/admin/import', [])->assertForbidden();
        $this->patch('/admin/lessons/'.$lesson->id.'/settings', [])->assertForbidden();
        foreach (['assessments/auto-marking.json', 'dist/data.js', 'dist/app.js', 'lesson-manifest.json'] as $path) {
            $this->get('/lessons/'.$lesson->id.'/assets/'.hash('sha256', $path))->assertNotFound();
        }
        $this->get('/lessons/'.$lesson->id)->assertOk();
        $this->get('/lessons/'.$lesson->id.'/assessment')->assertOk();
        $data = app(LessonAssembler::class)->assemble($lesson);
        foreach ($data['sections'] as $s) {
            $this->get('/lessons/'.$lesson->id.'/sections/'.$s['id'])->assertOk();
        }
        $videoKey = collect($data['assets'])->search(fn ($a) => $a['mime'] === 'video/mp4');
        $response = $this->withHeader('Range', 'bytes=0-1023')->get('/lessons/'.$lesson->id.'/assets/'.$videoKey);
        $response->assertStatus(206)->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Length', '1024');
        $private = json_decode(Storage::disk('local')->get($lesson->directory.'/source/assessments/auto-marking.json'), true);
        $answers = array_column($private['questions'], 'answer', 'id');
        foreach ($private['questions'] as $q) {
            if ($q['kind'] === 'short') {
                $answers[$q['id']] = $q['answer'][0];
            }
        }
        $result = app(AssessmentMarker::class)->mark($lesson, $answers);
        foreach ($result as $criterion) {
            $this->assertTrue($criterion['passed']);
        }
        $first = $private['rules'][0];
        foreach (array_slice($first['required_question_ids'], 0, 5) as $id) {
            $answers[$id] = 'wrong';
        }
        $result = app(AssessmentMarker::class)->mark($lesson, $answers);
        $this->assertFalse($result[0]['passed']);
        $this->assertEquals(16, $result[0]['max_marks']);
        $this->post('/lessons/'.$lesson->id.'/written', ['response' => 'My written response'])->assertRedirect();
        $this->assertDatabaseHas('assessment_attempts', ['user_id' => $user->id, 'lesson_id' => $lesson->id, 'kind' => 'written', 'status' => 'pending']);
    }

    public function test_malicious_and_incomplete_archives_leave_no_active_lesson(): void
    {
        Storage::fake('local');
        foreach (['../escape.json', 'C:/escape.json', 'dist/../../escape.json', 'bad.php', 'dist/CON.txt', 'dist/file.json.', 'dist/name /file.json'] as $name) {
            $file = tempnam(sys_get_temp_dir(), 'nth');
            $zip = new \ZipArchive;
            $zip->open($file, \ZipArchive::OVERWRITE);
            $zip->addFromString($name, 'bad');
            $zip->close();
            try {
                app(PackageImporter::class)->import($file);
                $this->fail('Unsafe archive accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            } finally {
                unlink($file);
            }
        }
        $this->assertEquals(0, Lesson::count());
        $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
    }

    public function test_invalid_content_and_protected_asset_aliases_are_rejected(): void
    {
        Storage::fake('local');
        $source = $this->pilotPath();
        foreach (['paragraph', 'table', 'written', 'protected'] as $case) {
            $file = tempnam(sys_get_temp_dir(), 'nth');
            copy($source, $file);
            $zip = new \ZipArchive;
            $zip->open($file);
            if ($case === 'protected') {
                $manifest = json_decode($zip->getFromName('lesson-manifest.json'), true);
                $manifest['assessments']['protected_marking_reference'] = 'dist/private/marking.txt';
                $zip->addFromString('dist/private/marking.txt', $zip->getFromName('assessments/auto-marking.json'));
                $manifest['media'][0]['accessibility']['transcript_path'] = 'private/marking.txt';
                $zip->addFromString('lesson-manifest.json', json_encode($manifest));
            } elseif ($case === 'written') {
                $zip->addFromString('dist/content/written-assessment.json', '{"text":[{"invalid":"object"}]}');
            } else {
                $path = 'dist/content/jce-bio-u10-s007.json';
                $content = json_decode($zip->getFromName($path), true);
                if ($case === 'paragraph') {
                    $content['blocks'][0]['text'] = ['invalid' => 'object'];
                } else {
                    $content['blocks'][3]['rows'] = 'invalid';
                }
                $zip->addFromString($path, json_encode($content));
            }
            $zip->close();
            try {
                app(PackageImporter::class)->import($file);
                $this->fail('Invalid '.$case.' package accepted.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            } finally {
                unlink($file);
            }
            $this->assertEquals(0, Lesson::count());
            $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
        }
    }

    private function enroll(User $learner, Lesson $lesson): void
    {
        $class = SchoolClass::factory()->create();
        $subject = Subject::factory()->create(['school_class_id' => $class->id, 'name' => 'Biology']);
        $unit = Unit::factory()->create(['subject_id' => $subject->id, 'title' => 'Plants']);
        $lesson->update(['unit_id' => $unit->id]);
        $learner->forceFill(['school_class_id' => $class->id])->save();
    }
}
