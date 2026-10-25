<?php

namespace App\Http\Controllers;

use App\Models\AssessmentAttempt;
use App\Models\Lesson;
use App\Models\ProgressRecord;
use App\Models\User;
use App\Services\AssessmentMarker;
use App\Services\LessonAssembler;
use App\Services\OfflineCurricula;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class OfflineController extends Controller
{
    public function __construct(private OfflineCurricula $curricula, private LessonAssembler $assembler) {}

    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Cache-Control' => 'private, no-store']);
    }

    private function canEdit(Request $request): bool
    {
        return in_array($request->user()->fresh()->role, ['admin', 'teacher'], true);
    }

    private function key(Request $request): string
    {
        return $request->user()->id.':'.Lesson::currentWorkspace();
    }

    private function profile(Request $request): void
    {
        abort_unless($request->input('profile_key') === $this->key($request), 409, 'The offline profile does not match this signed-in user and workspace.');
    }

    public function session(Request $request): JsonResponse
    {
        return $this->json(['key' => $this->key($request), 'user' => $request->user()->only('id', 'name', 'role'), 'workspace' => Lesson::currentWorkspace(), 'can_edit' => $this->canEdit($request), 'csrf_token' => csrf_token()]);
    }

    public function library(Request $request): JsonResponse
    {
        $lessons = Lesson::latest('id')->get()->map(function (Lesson $lesson): array {
            $data = $this->assembler->assemble($lesson);
            $textBytes = strlen(json_encode($data, JSON_THROW_ON_ERROR));
            $allBytes = $textBytes;
            foreach ($data['assets'] as $asset) {
                $allBytes += $asset['bytes'];
                if (! preg_match('/^(audio|video)\//', $asset['mime'])) {
                    $textBytes += $asset['bytes'];
                }
            }

            return [...$lesson->only('id', 'title', 'version', 'manifest_id'), 'bytes' => $allBytes, 'text_bytes' => $textBytes];
        });

        return $this->json(['lessons' => $lessons]);
    }

    public function lesson(Request $request, Lesson $lesson): JsonResponse
    {
        $data = $this->assembler->assemble($lesson, $this->canEdit($request));
        $files = [];
        foreach ($this->assembler->assemble($lesson)['assets'] as $key => $asset) {
            $files[] = ['url' => '/lessons/'.$lesson->id.'/assets/'.$key, ...$asset, 'sha256' => hash_file('sha256', Storage::disk('local')->path($lesson->directory.'/'.$asset['path']))];
        }
        unset($data['assets']);

        $document = $this->curricula->document($lesson, $this->canEdit($request));
        $head = Lesson::where('manifest_id', $lesson->manifest_id)->latest('id')->firstOrFail();

        return $this->json(['lesson' => $data, 'document' => $document, 'base_token' => $this->curricula->baseToken($lesson, $document, $this->key($request)), 'head' => ['id' => $head->id, 'sha' => $this->curricula->revisionFingerprint($head)], 'base_id' => $lesson->id, 'base_sha' => $lesson->sha256, 'files' => $files, 'progress' => ProgressRecord::where('user_id', $request->user()->id)->where('lesson_id', $lesson->id)->get()->keyBy('section_id')]);
    }

    private function editData(Request $request, bool $sync = false): array
    {
        abort_unless($this->canEdit($request), 403);
        $this->profile($request);
        abort_if((int) $request->server('CONTENT_LENGTH', 0) > 128 * 1024 * 1024, 413, 'Offline sync requests must be at most 128 MiB.');
        if (is_string($request->input('document'))) {
            $request->merge(['document' => json_decode($request->input('document'), true)]);
        }
        if (is_string($request->input('base_document'))) {
            $request->merge(['base_document' => json_decode($request->input('base_document'), true)]);
        }
        if (is_string($request->input('resolutions'))) {
            $request->merge(['resolutions' => json_decode($request->input('resolutions'), true)]);
        }
        abort_unless(is_array($request->input('document')), 422, 'The curriculum document must be a JSON object.');
        abort_unless(is_array($request->input('resolutions', [])), 422, 'Conflict resolutions must be a JSON object.');
        $request->validate(['media' => 'sometimes|array|max:300', 'media.*' => 'file|max:65536']);
        Validator::make($request->input('document', []), [
            'manifest_id' => 'required|string|max:180|regex:/^[A-Za-z0-9_.:-]+$/', 'title' => 'required|string|max:255', 'subject' => 'required|string|max:255', 'form' => 'required', 'language' => 'required|string|max:30', 'sections' => 'required|array|min:1|max:100', 'overview' => 'present|array', 'media' => 'present|array|max:100',
        ])->validate();
        foreach ($request->input('resolutions', []) as $resolution) {
            abort_unless(is_array($resolution) && in_array($resolution['choice'] ?? null, ['local', 'server', 'manual'], true), 422, 'Invalid conflict resolution.');
            abort_if($resolution['choice'] === 'manual' && ! array_key_exists('value', $resolution), 422, 'Manual resolutions require an explicit value.');
        }

        return $request->validate([
            'profile_key' => 'required|string', 'base_id' => 'nullable|integer', 'base_sha' => 'nullable|string|size:64', 'base_document' => 'required_with:base_id|array', 'base_token' => 'required_with:base_id|string|size:64', 'document' => 'required|array',
            'resolutions' => 'sometimes|array', 'resolutions.*.choice' => 'required|in:local,server,manual', 'resolutions.*.value' => 'present_if:resolutions.*.choice,manual', 'as_copy' => 'sometimes|boolean',
            ...($sync ? ['expected_head_sha' => 'present|nullable|string|size:64', 'operation_id' => 'required|uuid', 'version' => 'required|string|max:100|regex:/^[A-Za-z0-9_.-]+$/'] : []),
        ]);
    }

    public function review(Request $request): JsonResponse
    {
        return $this->json($this->curricula->review($this->editData($request)));
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $this->editData($request, true);
        $hashData = $data;
        foreach ($request->file('media', []) as $id => $upload) {
            $hashData['uploads'][$id] = hash_file('sha256', $upload->getRealPath());
        }
        $hash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));
        $created = null;
        try {
            $result = DB::transaction(function () use ($request, $data, $hash, &$created): array {
                User::orderBy('id')->lockForUpdate()->firstOrFail();
                $receipt = DB::table('offline_receipts')->where('user_id', $request->user()->id)->where('operation_id', $data['operation_id'])->first();
                if ($receipt) {
                    abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'Operation ID was already used for different content.');

                    return json_decode($receipt->result, true);
                }
                $base = isset($data['base_id']) ? Lesson::findOrFail($data['base_id']) : null;
                $family = $base?->manifest_id ?? $data['document']['manifest_id'];
                Lesson::where('manifest_id', $family)->lockForUpdate()->get();
                $review = $this->curricula->review($data);
                abort_unless(($review['head']['sha'] ?? null) === ($data['expected_head_sha'] ?? null), 409, 'The server changed. Review the latest revision before syncing.');
                if ($review['conflicts']) {
                    throw new HttpResponseException(response()->json(['message' => 'Resolve every conflict before syncing.', 'conflicts' => $review['conflicts']], 409, ['Cache-Control' => 'private, no-store']));
                }
                abort_unless(is_array($review['document']), 422, 'The resolved curriculum document must be a JSON object.');
                $original = $review['head'] ? Lesson::findOrFail($review['head']['id']) : $base;
                $created = $this->curricula->save($original, $review['document'], $data['version'], $data['as_copy'] ?? false, $request->file('media', []));
                if (! ($data['as_copy'] ?? false)) {
                    $previous = Lesson::where('manifest_id', $family)->where('id', '!=', $created->id)->latest('id')->lockForUpdate()->first();
                    abort_unless(($previous?->id) === ($review['head']['id'] ?? null) && ($previous ? $this->curricula->revisionFingerprint($previous) : null) === ($data['expected_head_sha'] ?? null), 409, 'The server changed while saving. Review again before syncing.');
                }
                $savedDocument = $this->curricula->document($created);
                $result = ['lesson_id' => $created->id, 'head' => ['id' => $created->id, 'sha' => $this->curricula->revisionFingerprint($created)], 'document' => $savedDocument, 'base_id' => $created->id, 'base_sha' => $created->sha256, 'base_token' => $this->curricula->baseToken($created, $savedDocument, $this->key($request))];
                DB::table('offline_receipts')->insert(['user_id' => $request->user()->id, 'operation_id' => $data['operation_id'], 'request_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

                return $result;
            });
        } catch (\Throwable $exception) {
            if ($created && ! Lesson::whereKey($created->id)->exists()) {
                Storage::disk('local')->deleteDirectory($created->directory);
            }
            throw $exception;
        }

        return $this->json($result);
    }

    public function state(Request $request, AssessmentMarker $marker): JsonResponse
    {
        $this->profile($request);
        abort_if((int) $request->server('CONTENT_LENGTH', 0) > 256 * 1024, 413, 'Offline learning events must be at most 256 KiB.');
        $data = $request->validate(['events' => 'required|array|max:100', 'events.*.id' => 'required|uuid|distinct', 'events.*.lesson_id' => 'required|integer', 'events.*.base_sha' => 'required|string|size:64', 'events.*.section_id' => 'nullable|string', 'events.*.type' => 'required|in:read,confidence,activity,written,assessment', 'events.*.value' => 'present', 'events.*.base_value' => 'sometimes|nullable', 'force_event_ids' => 'sometimes|array', 'force_event_ids.*' => 'uuid']);
        $acknowledged = DB::transaction(function () use ($request, $data, $marker): array {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $acknowledged = [];
            foreach ($data['events'] as $event) {
                $hash = hash('sha256', json_encode([$this->key($request), $event], JSON_THROW_ON_ERROR));
                $receipt = DB::table('offline_receipts')->where('user_id', $request->user()->id)->where('operation_id', $event['id'])->first();
                if ($receipt) {
                    abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'Event ID was already used for different content.');
                    $acknowledged[] = $event['id'];

                    continue;
                }
                $lesson = Lesson::findOrFail($event['lesson_id']);
                abort_unless(hash_equals($lesson->sha256, $event['base_sha']), 409, 'The event does not match the downloaded lesson version.');
                $value = $event['value'];
                if (in_array($event['type'], ['read', 'confidence', 'activity'], true)) {
                    $section = collect($this->assembler->assemble($lesson)['sections'])->firstWhere('id', $event['section_id'] ?? null);
                    abort_unless($section, 422, 'Unknown or unavailable section.');
                    $record = ProgressRecord::firstOrNew(['user_id' => $request->user()->id, 'lesson_id' => $lesson->id, 'section_id' => $section['id']]);
                    if ($event['type'] === 'activity') {
                        abort_unless(is_string($value) && in_array($value, array_column($section['activities'], 'id'), true), 422, 'Unknown activity.');
                        $record->activities = array_values(array_unique([...($record->activities ?? []), $value]));
                    } else {
                        $field = $event['type'];
                        abort_unless($field === 'read' ? is_bool($value) : ($value === null || (is_int($value) && $value >= 1 && $value <= 3)), 422, 'Invalid progress value.');
                        $current = $record->$field ?? ($field === 'read' ? false : null);
                        abort_unless($current === ($event['base_value'] ?? ($field === 'read' ? false : null)) || $current === $value || in_array($event['id'], $data['force_event_ids'] ?? [], true), 409, 'Progress changed on the server. Review and explicitly overwrite it.');
                        $record->$field = $value;
                    }
                    $record->save();
                } elseif ($event['type'] === 'written') {
                    abort_unless(is_string($value) && strlen($value) > 0 && strlen($value) <= 50000, 422, 'Written response must contain at most 50000 bytes.');
                    AssessmentAttempt::create(['user_id' => $request->user()->id, 'lesson_id' => $lesson->id, 'kind' => 'written', 'status' => 'pending', 'responses' => ['response' => $value]]);
                } else {
                    abort_unless(is_array($value) && count($value) <= 200 && strlen(json_encode($value)) <= 100000, 422, 'Invalid assessment responses.');
                    AssessmentAttempt::create(['user_id' => $request->user()->id, 'lesson_id' => $lesson->id, 'kind' => 'automatic', 'status' => 'marked', 'responses' => $value, 'result' => $marker->mark($lesson, $value)]);
                }
                DB::table('offline_receipts')->insert(['user_id' => $request->user()->id, 'operation_id' => $event['id'], 'request_hash' => $hash, 'result' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                $acknowledged[] = $event['id'];
            }

            return $acknowledged;
        });

        return $this->json(['acknowledged' => $acknowledged]);
    }
}
