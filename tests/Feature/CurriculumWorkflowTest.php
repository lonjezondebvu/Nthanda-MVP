<?php

namespace Tests\Feature;

use App\Models\ContentSetting;
use App\Models\Lesson;
use App\Models\User;
use App\Services\LessonAssembler;
use App\Services\PackageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CurriculumWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ?string $fixturePath = null;

    protected function tearDown(): void
    {
        if ($this->fixturePath !== null) {
            unlink($this->fixturePath);
        }
        parent::tearDown();
    }

    private function packagePath(): string
    {
        if ($this->fixturePath !== null) {
            return $this->fixturePath;
        }
        $this->fixturePath = tempnam(sys_get_temp_dir(), 'nthanda-fixture-');
        $manifest = [
            'schema_version' => 'nthanda-pilot-2.0', 'lesson_id' => 'TEST-CURRICULUM', 'lesson_version' => '1.0.0', 'title' => 'Test curriculum', 'language' => 'en',
            'source' => ['path' => 'source.txt', 'version' => '1', 'sha256' => hash('sha256', 'Curriculum source')],
            'syllabus' => ['curriculum_id' => 'TEST', 'edition' => '2026', 'subject' => 'Biology', 'form' => 1, 'printed_pages' => '1'],
            'tracking' => ['learner_timer_visible' => false, 'sections' => true, 'lesson_total' => true, 'storage' => 'local', 'idle_pause_seconds' => 60],
            'sections' => [
                ['id' => 'S1', 'order' => 1, 'title' => 'Plant parts', 'content_path' => 'content/one.json', 'criterion_ids' => ['C1'], 'block_ids' => ['B1']],
                ['id' => 'S2', 'order' => 2, 'title' => 'Investigation', 'content_path' => 'content/two.json', 'criterion_ids' => ['C1'], 'block_ids' => ['B2', 'B3']],
            ],
            'overview' => ['summary' => 'Imported test overview'], 'alternative_explanations' => [],
            'assessments' => ['learner_written_path' => 'written.json', 'protected_marking_reference' => 'assessments/marking.json', 'protected_source_rubric_reference' => 'assessments/rubric.json', 'automatic_policy' => 'Automatic checks', 'original_written_policy' => 'Teacher review'],
            'teaching_links' => [], 'assessment_links' => [],
            'media' => [['id' => 'M1', 'type' => 'image', 'path' => 'plant.svg', 'required' => true, 'production_status' => 'produced', 'review_status' => 'pending', 'reviewer' => null, 'approved_source_version' => null, 'section_id' => 'S1', 'anchor_block_id' => 'B1', 'placement' => 'after']],
            'review' => ['status' => 'pending', 'reviewer' => null, 'approved_source_version' => null], 'publication' => ['status' => 'preview', 'blockers' => []],
        ];
        $files = [
            'lesson-manifest.json' => $manifest,
            'dist/content/one.json' => ['id' => 'S1', 'blocks' => [['id' => 'B1', 'type' => 'paragraph', 'order' => 1, 'text' => 'Plant structure.']]],
            'dist/content/two.json' => ['id' => 'S2', 'blocks' => [['id' => 'B2', 'type' => 'paragraph', 'order' => 1, 'text' => 'Investigation text.'], ['id' => 'B3', 'type' => 'table', 'order' => 2, 'headers' => ['Factor', 'Result'], 'rows' => [['Light', 'Starch']]]]],
            'dist/written.json' => ['text' => ['Describe a plant.']],
            'assessments/marking.json' => ['questions' => [['id' => 'Q1', 'criterionId' => 'C1', 'kind' => 'short', 'domain' => 'knowledge', 'prompt' => 'Name a plant part.', 'answer' => ['leaf'], 'sectionIds' => ['S1'], 'maxMarks' => 1]], 'rules' => [['criterion_id' => 'C1', 'required_question_ids' => ['Q1'], 'max_marks' => 1, 'minimum_overall_ratio' => 1]]],
            'assessments/rubric.json' => [],
        ];
        $zip = new \ZipArchive;
        $zip->open($this->fixturePath, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $data) {
            $zip->addFromString($name, json_encode($data));
        }
        $zip->addFromString('source.txt', 'Curriculum source');
        $zip->addFromString('dist/plant.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $zip->close();

        return $this->fixturePath;
    }

    private function import(): Lesson
    {
        Storage::fake('local');

        return app(PackageImporter::class)->import($this->packagePath())['lesson'];
    }

    public function test_empty_trial_is_isolated_and_main_workspace_can_be_restored(): void
    {
        $lesson = $this->import();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/workspace', ['mode' => 'new'])->assertRedirect('/admin/lessons');
        $this->get('/admin/lessons')->assertInertia(fn (AssertableInertia $page) => $page->has('lessons', 0)->where('workspace.is_trial', true));
        $this->get('/admin/lessons/'.$lesson->id)->assertNotFound();
        $this->get('/lessons/'.$lesson->id.'/assessment')->assertNotFound();
        $trial = app(PackageImporter::class)->import($this->packagePath())['lesson'];
        $this->assertNotSame($lesson->id, $trial->id);
        $this->get('/admin/lessons')->assertInertia(fn (AssertableInertia $page) => $page->has('lessons', 1));
        $this->post('/admin/workspace', ['mode' => 'main'])->assertRedirect('/admin/lessons');
        $this->get('/admin/lessons')->assertInertia(fn (AssertableInertia $page) => $page->has('lessons', 1)->where('lessons.0.id', $lesson->id));
        $this->assertDatabaseCount('lessons', 2);
    }

    public function test_import_stream_reports_real_files_sections_and_completion(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $file = new UploadedFile($this->packagePath(), 'curriculum.zip', 'application/zip', null, true);

        $response = $this->actingAs($admin)->post('/admin/import-stream', ['package' => $file]);

        $response->assertOk();
        $events = array_map(fn (string $line): array => json_decode($line, true), explode("\n", trim($response->streamedContent())));
        $this->assertContains('extract', array_column($events, 'stage'));
        $this->assertContains('sections', array_column($events, 'stage'));
        $this->assertSame('complete', end($events)['stage']);
        $this->assertSame('/admin/lessons/1', end($events)['url']);
        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_bad_archive_reports_failure_without_creating_a_curriculum(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $file = UploadedFile::fake()->createWithContent('invalid.zip', 'not a zip');

        $response = $this->actingAs($admin)->post('/admin/import-stream', ['package' => $file]);

        $this->assertStringContainsString('"stage":"error"', $response->streamedContent());
        $this->assertDatabaseCount('lessons', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
    }

    public function test_editing_creates_a_downloadable_version_without_changing_the_original(): void
    {
        $lesson = $this->import();
        $admin = User::factory()->create(['role' => 'admin']);
        $source = app(LessonAssembler::class)->source($lesson);
        $this->actingAs($admin)->post('/lessons/'.$lesson->id.'/progress', ['section_id' => 'S1', 'read' => true, 'confidence' => 2])->assertRedirect();
        $this->post('/lessons/'.$lesson->id.'/written', ['response' => 'Original written work'])->assertRedirect();
        $sections = array_reverse($source['sections']);
        $sections[0]['title'] = 'Updated investigation';
        $sections[0]['blocks'][0]['text'] = 'Updated teaching text.';
        $sections[0]['blocks'][1]['rows'][0][0] = 'Updated light';

        $response = $this->actingAs($admin)->post('/admin/lessons/'.$lesson->id.'/versions', ['title' => 'Updated curriculum', 'version' => '2.2.0-test', 'sections' => $sections]);

        $response->assertSessionHasNoErrors();
        $updated = Lesson::where('version', '2.2.0-test')->firstOrFail();
        $response->assertRedirect('/admin/lessons/'.$updated->id);
        $data = app(LessonAssembler::class)->assemble($updated);
        $this->assertSame('Updated curriculum', $data['title']);
        $this->assertSame('Updated investigation', $data['sections'][0]['title']);
        $this->assertSame('Updated teaching text.', $data['sections'][0]['blocks'][0]['text']);
        $this->assertSame('Updated light', $data['sections'][0]['blocks'][1]['rows'][0][0]);
        $this->assertSame($source, app(LessonAssembler::class)->source($lesson));
        $download = $this->get('/admin/lessons/'.$updated->id.'/download')->assertOk()->assertDownload();
        $export = Storage::disk('local')->path($updated->directory.'/original.zip');
        $zip = new \ZipArchive;
        $zip->open($export);
        $manifest = json_decode($zip->getFromName('lesson-manifest.json'), true);
        $zip->close();
        $this->assertSame('2.2.0-test', $manifest['lesson_version']);
        $this->assertSame($sections[0]['id'], $manifest['sections'][0]['id']);
        $this->assertSame($updated->id, app(PackageImporter::class)->import($export)['lesson']->id);
        $this->assertDatabaseCount('lessons', 2);
        $this->assertDatabaseHas('progress_records', ['lesson_id' => $lesson->id, 'user_id' => $admin->id, 'read' => true, 'confidence' => 2]);
        $this->assertDatabaseHas('assessment_attempts', ['lesson_id' => $lesson->id, 'user_id' => $admin->id, 'kind' => 'written', 'status' => 'pending']);
        $this->assertDatabaseMissing('progress_records', ['lesson_id' => $updated->id]);
        $this->post('/admin/workspace', ['mode' => 'new'])->assertRedirect();
        $reimported = app(PackageImporter::class)->import($download->baseResponse->getFile()->getPathname())['lesson'];
        $roundtrip = app(LessonAssembler::class)->assemble($reimported);
        $this->assertSame('Updated teaching text.', $roundtrip['sections'][0]['blocks'][0]['text']);
        $this->assertSame('Updated light', $roundtrip['sections'][0]['blocks'][1]['rows'][0][0]);
        $this->assertSame($data['sections'][1]['activities'], $roundtrip['sections'][1]['activities']);
    }

    public function test_invalid_edits_and_duplicate_versions_leave_the_original_intact(): void
    {
        $lesson = $this->import();
        $admin = User::factory()->create(['role' => 'admin']);
        $source = app(LessonAssembler::class)->source($lesson);
        $payload = ['title' => 'Changed', 'version' => $lesson->version, 'sections' => $source['sections']];
        $this->actingAs($admin)->post('/admin/lessons/'.$lesson->id.'/versions', $payload)->assertSessionHasErrors('version');
        $payload['version'] = 'new-version';
        $payload['sections'][0]['blocks'][0]['id'] = 'unknown-block';
        $this->post('/admin/lessons/'.$lesson->id.'/versions', $payload)->assertSessionHasErrors('sections');
        $this->assertDatabaseCount('lessons', 1);
        $this->assertSame($source, app(LessonAssembler::class)->source($lesson));
    }

    public function test_learners_cannot_import_edit_export_or_switch_workspaces(): void
    {
        $lesson = $this->import();
        $learner = User::factory()->create(['role' => 'learner']);
        $this->actingAs($learner)->post('/admin/workspace', ['mode' => 'new'])->assertForbidden();
        $this->post('/admin/import-stream')->assertForbidden();
        $this->post('/admin/lessons/'.$lesson->id.'/versions')->assertForbidden();
        $this->get('/admin/lessons/'.$lesson->id.'/download')->assertForbidden();
    }

    public function test_download_roundtrips_visibility_and_section_order(): void
    {
        $lesson = $this->import();
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([['section', 'S1', false, true, 2], ['section', 'S2', true, false, 1], ['block', 'B2', false, false, null], ['media', 'M1', false, false, null]] as [$kind, $id, $enabled, $deleted, $position]) {
            ContentSetting::create(['lesson_id' => $lesson->id, 'kind' => $kind, 'item_id' => $id, 'enabled' => $enabled, 'deleted' => $deleted, 'position' => $position]);
        }
        $download = $this->actingAs($admin)->get('/admin/lessons/'.$lesson->id.'/download')->assertOk()->assertDownload();
        $inline = $this->get('/admin/lessons/'.$lesson->id.'/download?inline=1')->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('private', $inline->headers->get('Cache-Control'));
        $this->post('/admin/workspace', ['mode' => 'new'])->assertRedirect();
        $imported = app(PackageImporter::class)->import($download->baseResponse->getFile()->getPathname())['lesson'];

        $data = app(LessonAssembler::class)->assemble($imported);
        $this->assertSame(['S2'], array_column($data['sections'], 'id'));
        $this->assertSame(['B3'], array_column($data['sections'][0]['blocks'], 'id'));
        $this->assertDatabaseHas('content_settings', ['lesson_id' => $imported->id, 'kind' => 'media', 'item_id' => 'M1', 'enabled' => false]);
        $this->assertSame(['S2', 'S1'], array_column(app(LessonAssembler::class)->assemble($imported, true)['sections'], 'id'));
    }

    public function test_external_zip_changes_import_overview_and_reject_corrupt_settings(): void
    {
        $lesson = $this->import();
        $temporary = tempnam(sys_get_temp_dir(), 'nthanda-update-');
        copy($this->packagePath(), $temporary);
        try {
            $zip = new \ZipArchive;
            $zip->open($temporary);
            $manifest = json_decode($zip->getFromName('lesson-manifest.json'), true);
            $manifest['lesson_version'] = 'external-2';
            $manifest['overview'] = ['summary' => 'Externally updated curriculum overview'];
            $zip->addFromString('lesson-manifest.json', json_encode($manifest));
            $zip->close();
            $updated = app(PackageImporter::class)->import($temporary)['lesson'];
            $this->assertSame('Externally updated curriculum overview', app(LessonAssembler::class)->assemble($updated)['overview']['summary']);

            $zip->open($temporary);
            $manifest['lesson_version'] = 'external-3';
            $zip->addFromString('lesson-manifest.json', json_encode($manifest));
            $zip->addFromString('studio-settings.json', '[{"kind":"section","item_id":"unknown","enabled":true,"deleted":false,"position":1}]');
            $zip->close();
            try {
                app(PackageImporter::class)->import($temporary);
                $this->fail('Invalid settings were accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('package', $exception->errors());
            }
            $this->assertDatabaseCount('lessons', 2);
            $this->assertCount(2, Storage::disk('local')->directories('imports'));
        } finally {
            unlink($temporary);
        }
    }

    public function test_malformed_export_metadata_never_creates_a_curriculum(): void
    {
        Storage::fake('local');
        $activity = ['id' => 'P1', 'title' => 'Practice', 'feedback' => 'Try again', 'section_ids' => ['S1'], 'purpose' => 'formative', 'counts_for_mastery' => false, 'prompt_parts' => ['first' => ['part_id' => 'part', 'text' => 'Hello']]];
        $media = ['id' => 'M2', 'type' => 'pronunciation', 'path' => 'plant.svg', 'section_id' => 'S1', 'anchor_block_id' => 'B1', 'placement' => 'after'];
        $cases = [
            ['activities' => [$activity], 'overview' => [], 'additional_media' => []],
            ['activities' => [], 'overview' => [], 'additional_media' => [[...$media, 'term' => ['bad']]]],
            ['activities' => [], 'overview' => [], 'additional_media' => [[...$media, 'aliases' => ['alias' => 'bad']]]],
            ['activities' => [], 'overview' => [], 'additional_media' => [[...$media, 'references' => [['section_id' => 'unknown']]]]],
            ['activities' => [], 'overview' => [], 'additional_media' => [[...$media, 'id' => 'M1']]],
        ];
        foreach ($cases as $metadata) {
            $temporary = tempnam(sys_get_temp_dir(), 'nthanda-metadata-');
            copy($this->packagePath(), $temporary);
            try {
                $zip = new \ZipArchive;
                $zip->open($temporary);
                $zip->addFromString('studio-compatibility.json', json_encode($metadata));
                $zip->close();
                try {
                    app(PackageImporter::class)->import($temporary);
                    $this->fail('Malformed compatibility metadata was accepted.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('package', $exception->errors());
                }
            } finally {
                unlink($temporary);
            }
        }
        $this->assertDatabaseCount('lessons', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
    }
}
