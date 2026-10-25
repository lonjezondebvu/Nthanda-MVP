<?php

namespace Tests\Feature;

use App\Models\ContentSetting;
use App\Models\Lesson;
use App\Models\ProgressRecord;
use App\Models\User;
use App\Services\CurriculumPackages;
use App\Services\LessonAssembler;
use App\Services\OfflineCurricula;
use App\Services\PackageImporter;
use App\Services\ThreeWayMerge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private function document(): array
    {
        return ['manifest_id' => 'test-offline', 'title' => 'Offline lesson', 'subject' => 'Biology', 'form' => '1', 'language' => 'en', 'overview' => ['summary' => 'A new draft.'], 'sections' => [['id' => 's1', 'title' => 'First section', 'criterion_ids' => ['c1'], 'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'text' => 'Original text'], ['id' => 'b2', 'type' => 'paragraph', 'text' => 'Another paragraph']]]], 'media' => []];
    }

    private function editor(): User
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        return $user;
    }

    private function payload(User $user, array $document, ?Lesson $base = null): array
    {
        $snapshot = $base ? app(OfflineCurricula::class)->document($base) : null;
        $signed = $base ? ['base_document' => $snapshot, 'base_token' => app(OfflineCurricula::class)->baseToken($base, $snapshot, $user->id.':main')] : [];

        return [...$signed, 'profile_key' => $user->id.':main', 'base_id' => $base?->id, 'base_sha' => $base?->sha256, 'document' => $document, 'resolutions' => [], 'expected_head_sha' => $base ? app(OfflineCurricula::class)->revisionFingerprint($base) : null, 'operation_id' => (string) Str::uuid(), 'version' => '1.0'];
    }

    public function test_new_draft_sync_is_idempotent_and_snapshot_has_no_marking_data(): void
    {
        $user = $this->editor();
        $payload = $this->payload($user, $this->document());
        $result = $this->postJson('/offline-api/sync', $payload)->assertOk()->json();
        $this->assertDatabaseCount('lessons', 1);
        $this->postJson('/offline-api/sync', $payload)->assertOk()->assertJsonPath('lesson_id', $result['lesson_id']);
        $this->assertDatabaseCount('lessons', 1);
        $snapshot = $this->getJson('/offline-api/lessons/'.$result['lesson_id'])->assertOk();
        $snapshot->assertJsonPath('lesson.publication.status', 'draft')->assertJsonPath('document.sections.0.blocks.0.text', 'Original text')->assertJsonPath('lesson.questions', []);
        $this->assertStringNotContainsString('protected_marking_reference', $snapshot->getContent());
        $payload['document']['title'] = 'Changed';
        $this->postJson('/offline-api/sync', $payload)->assertStatus(409);
    }

    public function test_learners_cannot_edit_and_mismatched_profiles_cannot_sync(): void
    {
        $user = $this->editor();
        $payload = $this->payload($user, $this->document());
        $payload['profile_key'] = 'another:main';
        $this->postJson('/offline-api/sync', $payload)->assertStatus(409);
        $user->forceFill(['role' => 'learner'])->save();
        $this->postJson('/offline-api/review', $payload)->assertForbidden();
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_review_merges_independent_block_changes_and_requires_explicit_resolution_for_collisions(): void
    {
        $base = $this->document();
        $local = $base;
        $server = $base;
        $local['sections'][0]['blocks'][0]['text'] = 'Local';
        $server['sections'][0]['blocks'][1]['text'] = 'Server';
        $result = app(ThreeWayMerge::class)->merge($base, $local, $server);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame('Local', $result['document']['sections'][0]['blocks'][0]['text']);
        $this->assertSame('Server', $result['document']['sections'][0]['blocks'][1]['text']);
        $server['sections'][0]['blocks'][0]['text'] = 'Collision';
        $result = app(ThreeWayMerge::class)->merge($base, $local, $server);
        $this->assertSame('/sections/s1/blocks/b1/text', $result['conflicts'][0]['path']);
        $result = app(ThreeWayMerge::class)->merge($base, $local, $server, ['/sections/s1/blocks/b1/text' => ['choice' => 'manual', 'value' => 'Resolved']]);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame('Resolved', $result['document']['sections'][0]['blocks'][0]['text']);
    }

    public function test_stale_server_head_is_rejected_and_versions_preserve_settings_and_history(): void
    {
        $user = $this->editor();
        $base = app(OfflineCurricula::class)->save(null, $this->document(), '1.0', false);
        ContentSetting::create(['lesson_id' => $base->id, 'kind' => 'block', 'item_id' => 'b2', 'enabled' => false, 'deleted' => false]);
        ProgressRecord::create(['user_id' => $user->id, 'lesson_id' => $base->id, 'section_id' => 's1', 'read' => true]);
        $doc = app(OfflineCurricula::class)->document($base);
        $doc['title'] = 'Changed title';
        $payload = $this->payload($user, $doc, $base);
        $payload['version'] = '1.1';
        $result = $this->postJson('/offline-api/sync', $payload)->assertOk()->json();
        $this->assertDatabaseHas('content_settings', ['lesson_id' => $result['lesson_id'], 'item_id' => 'b2', 'enabled' => false]);
        $this->assertDatabaseHas('progress_records', ['lesson_id' => $base->id, 'read' => true]);
        $payload['operation_id'] = (string) Str::uuid();
        $payload['version'] = '1.2';
        $this->postJson('/offline-api/sync', $payload)->assertStatus(409);
        $this->assertDatabaseCount('lessons', 2);
    }

    public function test_progress_conflicts_are_atomic_and_explicit_overwrites_are_idempotent(): void
    {
        $user = $this->editor();
        $lesson = app(OfflineCurricula::class)->save(null, $this->document(), '1.0', false);
        ProgressRecord::create(['user_id' => $user->id, 'lesson_id' => $lesson->id, 'section_id' => 's1', 'confidence' => 3]);
        $id = (string) Str::uuid();
        $payload = ['profile_key' => $user->id.':main', 'events' => [['id' => $id, 'lesson_id' => $lesson->id, 'base_sha' => $lesson->sha256, 'section_id' => 's1', 'type' => 'confidence', 'value' => 1, 'base_value' => 2]]];
        $this->postJson('/offline-api/state', $payload)->assertStatus(409);
        $this->assertDatabaseHas('progress_records', ['lesson_id' => $lesson->id, 'confidence' => 3]);
        $payload['force_event_ids'] = [$id];
        $this->postJson('/offline-api/state', $payload)->assertOk()->assertJsonPath('acknowledged.0', $id);
        $this->postJson('/offline-api/state', $payload)->assertOk();
        $this->assertDatabaseHas('progress_records', ['lesson_id' => $lesson->id, 'confidence' => 1]);
        $this->assertDatabaseCount('offline_receipts', 1);
    }

    public function test_media_uploads_are_hash_verified_and_packaged_with_generated_paths(): void
    {
        $user = $this->editor();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
        $document = $this->document();
        $document['media'] = [['id' => 'image1', 'type' => 'image', 'path' => 'ignored.png', 'sha256' => hash('sha256', $png), 'bytes' => strlen($png), 'section_id' => 's1', 'anchor_block_id' => 'b1', 'placement' => 'after', 'accessibility' => ['description' => 'A test image']]];
        $payload = $this->payload($user, $document);
        $upload = UploadedFile::fake()->createWithContent('photo.png', $png);
        $result = $this->post('/offline-api/sync', [...$payload, 'media' => ['image1' => $upload]], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('media/offline/image1.png', $result['document']['media'][0]['path']);
        $this->getJson('/offline-api/lessons/'.$result['lesson_id'])->assertOk()->assertJsonPath('files.0.mime', 'image/png');
        $payload['operation_id'] = (string) Str::uuid();
        $payload['document']['media'][0]['sha256'] = str_repeat('0', 64);
        $payload['document']['manifest_id'] = 'invalid-upload';
        $this->post('/offline-api/sync', [...$payload, 'media' => ['image1' => $upload]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_edit_delete_collisions_and_reorder_conflicts_are_explicit(): void
    {
        $base = $this->document();
        $local = $base;
        $server = $base;
        array_shift($local['sections'][0]['blocks']);
        $server['sections'][0]['blocks'][0]['text'] = 'Server edit';
        $result = app(ThreeWayMerge::class)->merge($base, $local, $server);
        $this->assertSame('/sections/s1/blocks/b1', $result['conflicts'][0]['path']);
        $this->assertTrue($result['conflicts'][0]['local_missing']);
        $this->assertFalse($result['conflicts'][0]['base_missing']);
    }

    public function test_cross_workspace_snapshots_are_hidden_and_teachers_can_create_separate_copies(): void
    {
        $user = $this->editor();
        $user->forceFill(['role' => 'teacher'])->save();
        $base = app(OfflineCurricula::class)->save(null, $this->document(), '1.0', false);
        $payload = $this->payload($user, $this->document(), $base);
        $payload['as_copy'] = true;
        $payload['expected_head_sha'] = null;
        $result = $this->postJson('/offline-api/sync', $payload)->assertOk()->json();
        $this->assertNotSame($base->manifest_id, $result['document']['manifest_id']);
        $this->withSession(['studio_workspace' => 'other'])->getJson('/offline-api/lessons/'.$base->id)->assertNotFound();
    }

    public function test_protected_marking_files_and_policies_survive_an_offline_revision(): void
    {
        $user = $this->editor();
        $draft = app(OfflineCurricula::class)->save(null, $this->document(), '1.0', false);
        $manifest = app(LessonAssembler::class)->source($draft)['manifest'];
        $manifest['lesson_version'] = 'assessed';
        $manifest['publication']['status'] = 'preview-only';
        $manifest['assessment_links'] = [['assessment_id' => 'q1', 'sections' => ['s1']]];
        $marking = ['questions' => [['id' => 'q1', 'criterionId' => 'c1', 'kind' => 'mc', 'answer' => 'secret-key', 'maxMarks' => 1, 'prompt' => 'Choose one', 'options' => ['secret-key', 'other'], 'domain' => 'knowledge', 'sectionIds' => ['s1']]], 'rules' => [['criterion_id' => 'c1', 'required_question_ids' => ['q1'], 'max_marks' => 1, 'minimum_overall_ratio' => 1]]];
        $zip = app(CurriculumPackages::class)->export($draft, ['lesson-manifest.json' => $manifest, 'assessments/marking.json' => $marking]);
        try {
            $base = app(PackageImporter::class)->import($zip)['lesson'];
        } finally {
            unlink($zip);
        }
        $document = app(OfflineCurricula::class)->document($base);
        $document['title'] = 'Offline edited assessed curriculum';
        $payload = $this->payload($user, $document, $base);
        $payload['version'] = 'revision';
        $result = $this->postJson('/offline-api/sync', $payload)->assertOk()->json();
        $updated = Lesson::findOrFail($result['lesson_id']);
        $this->assertSame($marking, app(LessonAssembler::class)->marking($updated));
        $this->assertSame($manifest['assessments'], app(LessonAssembler::class)->source($updated)['manifest']['assessments']);
        $snapshot = $this->getJson('/offline-api/lessons/'.$updated->id)->assertOk()->json();
        $this->assertArrayNotHasKey('answer', $snapshot['lesson']['questions'][0]);
        $this->assertArrayNotHasKey('assessments', $snapshot['document']);
    }

    public function test_changed_head_during_save_rolls_back_the_revision_and_removes_its_files(): void
    {
        $user = $this->editor();
        $base = app(OfflineCurricula::class)->save(null, $this->document(), '1.0', false);
        $importer = app(PackageImporter::class);
        $directory = null;
        $mock = \Mockery::mock(PackageImporter::class);
        $mock->shouldReceive('import')->once()->andReturnUsing(function (string $path) use ($importer, $base, &$directory): array {
            $result = $importer->import($path);
            $directory = $result['lesson']->directory;
            Lesson::create(['manifest_id' => $base->manifest_id, 'version' => 'racing', 'title' => 'Concurrent head', 'sha256' => str_repeat('e', 64), 'directory' => 'race-test', 'report' => []]);

            return $result;
        });
        $this->app->instance(PackageImporter::class, $mock);
        $payload = $this->payload($user, $this->document(), $base);
        $payload['version'] = '1.1';
        $this->postJson('/offline-api/sync', $payload)->assertStatus(409);
        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseCount('offline_receipts', 0);
        $this->assertFalse(Storage::disk('local')->exists($directory));
    }

    public function test_captions_are_verified_and_included_as_learner_assets(): void
    {
        $user = $this->editor();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1" /></svg>';
        $captions = "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nA plant.\n";
        $document = $this->document();
        $document['media'] = [['id' => 'legacy:image', 'type' => 'image', 'path' => 'image.svg', 'sha256' => hash('sha256', $svg), 'bytes' => strlen($svg), 'section_id' => 's1', 'anchor_block_id' => 'b1', 'placement' => 'after', 'accessibility' => ['description' => 'Illustration', 'captions_path' => 'captions.vtt', 'captions_sha256' => hash('sha256', $captions), 'captions_bytes' => strlen($captions)]]];
        $payload = $this->payload($user, $document);
        $uploads = ['legacy:image' => UploadedFile::fake()->createWithContent('image.svg', $svg), 'legacy:image:captions' => UploadedFile::fake()->createWithContent('captions.vtt', $captions)];
        $result = $this->post('/offline-api/sync', [...$payload, 'media' => $uploads], ['Accept' => 'application/json'])->assertOk()->json();
        $snapshot = $this->getJson('/offline-api/lessons/'.$result['lesson_id'])->assertOk()->json();
        $this->assertSame(['image/svg+xml', 'text/vtt'], array_column($snapshot['files'], 'mime'));
        $this->assertStringStartsWith('media/offline/', $result['document']['media'][0]['accessibility']['captions_path']);
    }

    public function test_svg_active_content_is_rejected_without_creating_a_curriculum(): void
    {
        $user = $this->editor();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $document = $this->document();
        $document['media'] = [['id' => 'image', 'type' => 'image', 'path' => 'image.svg', 'sha256' => hash('sha256', $svg), 'bytes' => strlen($svg), 'section_id' => 's1', 'anchor_block_id' => 'b1', 'placement' => 'after']];
        $payload = $this->payload($user, $document);
        $this->post('/offline-api/sync', [...$payload, 'media' => ['image' => UploadedFile::fake()->createWithContent('image.svg', $svg)]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('lessons', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    public function test_new_revision_can_add_reorder_and_remove_unreferenced_content_without_losing_old_settings(): void
    {
        $user = $this->editor();
        $base = app(OfflineCurricula::class)->save(null, $this->document(), '1.0', false);
        ContentSetting::create(['lesson_id' => $base->id, 'kind' => 'section', 'item_id' => 's1', 'enabled' => true, 'deleted' => false, 'position' => 1]);
        ContentSetting::create(['lesson_id' => $base->id, 'kind' => 'block', 'item_id' => 'b2', 'enabled' => false, 'deleted' => false]);
        $document = $this->document();
        array_pop($document['sections'][0]['blocks']);
        array_unshift($document['sections'], ['id' => 's2', 'title' => 'New first section', 'criterion_ids' => ['c2'], 'blocks' => [['id' => 'new-block', 'type' => 'paragraph', 'text' => 'New content']]]);
        $payload = $this->payload($user, $document, $base);
        $payload['version'] = '1.1';
        $result = $this->postJson('/offline-api/sync', $payload)->assertOk()->json();
        $this->assertSame(['s2', 's1'], array_column($result['document']['sections'], 'id'));
        $this->assertDatabaseHas('content_settings', ['lesson_id' => $base->id, 'item_id' => 'b2']);
        $this->assertDatabaseMissing('content_settings', ['lesson_id' => $result['lesson_id'], 'item_id' => 'b2']);
        $this->assertDatabaseHas('content_settings', ['lesson_id' => $result['lesson_id'], 'item_id' => 's1', 'position' => 2]);
    }

    public function test_invalid_event_rolls_back_other_events_and_written_retries_do_not_duplicate_history(): void
    {
        $user = $this->editor();
        $lesson = app(OfflineCurricula::class)->save(null, $this->document(), '1.0', false);
        $event = ['id' => (string) Str::uuid(), 'lesson_id' => $lesson->id, 'base_sha' => $lesson->sha256, 'type' => 'written', 'value' => 'My response'];
        $invalid = ['id' => (string) Str::uuid(), 'lesson_id' => $lesson->id, 'base_sha' => $lesson->sha256, 'type' => 'read', 'section_id' => 'missing', 'value' => true];
        $payload = ['profile_key' => $user->id.':main', 'events' => [$event, $invalid]];
        $this->postJson('/offline-api/state', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('assessment_attempts', 0);
        $this->assertDatabaseCount('offline_receipts', 0);
        $payload['events'] = [$event];
        $this->postJson('/offline-api/state', $payload)->assertOk();
        $this->postJson('/offline-api/state', $payload)->assertOk();
        $this->assertDatabaseCount('assessment_attempts', 1);
        $this->assertDatabaseHas('assessment_attempts', ['user_id' => $user->id, 'lesson_id' => $lesson->id, 'kind' => 'written', 'status' => 'pending']);
    }

    public function test_wav_upload_is_verified_packaged_and_served_with_audio_mime(): void
    {
        $user = $this->editor();
        $samples = str_repeat(chr(0), 160);
        $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
        $document = $this->document();
        $document['media'] = [['id' => 'recording', 'type' => 'audio', 'path' => 'recording.wav', 'sha256' => hash('sha256', $wav), 'bytes' => strlen($wav), 'section_id' => 's1', 'anchor_block_id' => 'b1', 'placement' => 'after', 'accessibility' => ['description' => 'Recorded explanation']]];
        $payload = $this->payload($user, $document);
        $upload = UploadedFile::fake()->createWithContent('recording.wav', $wav);
        $result = $this->post('/offline-api/sync', [...$payload, 'media' => ['recording' => $upload]], ['Accept' => 'application/json'])->assertOk()->json();
        $snapshot = $this->getJson('/offline-api/lessons/'.$result['lesson_id'])->assertOk()->json();
        $this->assertSame('audio/wav', $snapshot['files'][0]['mime']);
        $this->get($snapshot['files'][0]['url'])->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->assertSame('media/offline/recording.wav', $result['document']['media'][0]['path']);
    }

    public function test_null_merged_document_is_rejected_before_saving(): void
    {
        $user = $this->editor();
        $mock = \Mockery::mock(OfflineCurricula::class);
        $mock->shouldReceive('review')->once()->andReturn(['head' => null, 'conflicts' => [], 'document' => null]);
        $this->app->instance(OfflineCurricula::class, $mock);
        $this->postJson('/offline-api/sync', $this->payload($user, $this->document()))->assertUnprocessable();
        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('offline_receipts', 0);
    }

    public function test_manual_order_resolution_requires_an_array_instead_of_crashing(): void
    {
        $base = $this->document();
        $base['sections'][0]['blocks'][] = ['id' => 'b3', 'type' => 'paragraph', 'text' => 'Third'];
        $local = $base;
        $server = $base;
        $local['sections'][0]['blocks'] = array_reverse($local['sections'][0]['blocks']);
        $server['sections'][0]['blocks'] = [$base['sections'][0]['blocks'][1], $base['sections'][0]['blocks'][0], $base['sections'][0]['blocks'][2]];
        $this->expectException(ValidationException::class);
        app(ThreeWayMerge::class)->merge($base, $local, $server, ['/sections/s1/blocks/@order' => ['choice' => 'manual', 'value' => null]]);
    }

    public function test_signed_downloaded_base_detects_competing_online_and_offline_reorders(): void
    {
        $user = $this->editor();
        $document = $this->document();
        foreach ([2, 3] as $number) {
            $document['sections'][] = ['id' => 's'.$number, 'title' => 'Section '.$number, 'criterion_ids' => ['c'.$number], 'blocks' => [['id' => 'b'.$number.'-new', 'type' => 'paragraph', 'text' => 'Section content']]];
        }
        $base = app(OfflineCurricula::class)->save(null, $document, '1.0', false);
        $snapshot = $this->getJson('/offline-api/lessons/'.$base->id)->assertOk()->json();
        $local = $snapshot['document'];
        $local['sections'] = array_reverse($local['sections']);
        $payload = $this->payload($user, $local, $base);
        $payload['base_document'] = $snapshot['document'];
        $payload['base_token'] = $snapshot['base_token'];
        $oldFingerprint = $snapshot['head']['sha'];
        foreach (['s2', 's1', 's3'] as $position => $id) {
            ContentSetting::create(['lesson_id' => $base->id, 'kind' => 'section', 'item_id' => $id, 'enabled' => true, 'deleted' => false, 'position' => $position + 1]);
        }
        $review = $this->postJson('/offline-api/review', $payload)->assertOk()->json();
        $this->assertNotSame($oldFingerprint, $review['head']['sha']);
        $this->assertSame('/sections/@order', $review['conflicts'][0]['path']);
        $this->assertSame(['s1', 's2', 's3'], $review['conflicts'][0]['base']);
        $this->assertSame(['s3', 's2', 's1'], $review['conflicts'][0]['local']);
        $this->assertSame(['s2', 's1', 's3'], $review['conflicts'][0]['server']);
        $payload['version'] = '1.1';
        $this->postJson('/offline-api/sync', $payload)->assertStatus(409);
        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_tampered_or_unsigned_base_is_rejected_but_key_order_does_not_change_signature(): void
    {
        $user = $this->editor();
        $base = app(OfflineCurricula::class)->save(null, $this->document(), '1.0', false);
        $payload = $this->payload($user, $this->document(), $base);
        $payload['base_document'] = array_reverse($payload['base_document'], true);
        $this->postJson('/offline-api/review', $payload)->assertOk();
        $payload['base_document']['title'] = 'Forged base';
        $this->postJson('/offline-api/review', $payload)->assertStatus(409);
        unset($payload['base_token']);
        $this->postJson('/offline-api/review', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_online_settings_change_during_save_is_detected_and_created_package_is_removed(): void
    {
        $user = $this->editor();
        $base = app(OfflineCurricula::class)->save(null, $this->document(), '1.0', false);
        $payload = $this->payload($user, $this->document(), $base);
        $payload['version'] = '1.1';
        $importer = app(PackageImporter::class);
        $directory = null;
        $mock = \Mockery::mock(PackageImporter::class);
        $mock->shouldReceive('import')->once()->andReturnUsing(function (string $path) use ($importer, $base, &$directory): array {
            $result = $importer->import($path);
            $directory = $result['lesson']->directory;
            ContentSetting::create(['lesson_id' => $base->id, 'kind' => 'section', 'item_id' => 's1', 'position' => 5, 'enabled' => true, 'deleted' => false]);

            return $result;
        });
        $this->app->instance(PackageImporter::class, $mock);
        $this->postJson('/offline-api/sync', $payload)->assertStatus(409);
        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseCount('offline_receipts', 0);
        $this->assertFalse(Storage::disk('local')->exists($directory));
    }

    public function test_json_encoded_base_preserves_empty_and_whitespace_text_through_request_middleware(): void
    {
        $user = $this->editor();
        $document = $this->document();
        $document['sections'][0]['blocks'][0]['text'] = '';
        $document['sections'][0]['blocks'][1]['text'] = '  Deliberate spacing  ';
        $document['overview'] = ['summary' => '', 'introduction' => ['', '  Whitespace  ']];
        $base = app(OfflineCurricula::class)->save(null, $document, '1.0', false);
        $snapshot = $this->getJson('/offline-api/lessons/'.$base->id)->assertOk()->json();
        $payload = $this->payload($user, $snapshot['document'], $base);
        $payload['document'] = json_encode($snapshot['document'], JSON_THROW_ON_ERROR);
        $payload['base_document'] = json_encode($snapshot['document'], JSON_THROW_ON_ERROR);
        $payload['base_token'] = $snapshot['base_token'];
        $payload['resolutions'] = '{}';
        $this->postJson('/offline-api/review',$payload)->assertOk()->assertJsonPath('conflicts',[])->assertJsonPath('document.sections.0.blocks.0.text','')->assertJsonPath('document.overview.introduction.1','  Whitespace  ');
    }
}
