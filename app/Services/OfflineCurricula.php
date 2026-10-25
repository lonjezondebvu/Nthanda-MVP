<?php

namespace App\Services;

use App\Models\ContentSetting;
use App\Models\Lesson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use ZipArchive;

class OfflineCurricula
{
    public function __construct(private LessonAssembler $assembler, private ManifestParser $parser) {}

    public function document(Lesson $lesson, bool $editor = true): array
    {
        $source = $this->assembler->source($lesson);
        $assembled = $this->assembler->assemble($lesson, $editor);
        $manifest = $source['manifest'];

        $visibleMedia = array_merge(...array_map(fn (array $section): array => array_column($section['media'], 'id'), $assembled['sections']));
        foreach ($source['media'] as $item) {
            if (in_array($item['id'], $visibleMedia, true)) {
                $visibleMedia = [...$visibleMedia, ...($item['chunk_ids'] ?? [])];
            }
        }

        return ['manifest_id' => $lesson->manifest_id, 'title' => $lesson->title, 'subject' => $manifest['syllabus']['subject'], 'form' => $manifest['syllabus']['form'], 'language' => $manifest['language'], 'overview' => $assembled['overview'],
            'sections' => array_map(fn (array $section): array => ['id' => $section['id'], 'title' => $section['title'], 'criterion_ids' => $section['criterion_ids'], 'blocks' => array_map(fn (array $block): array => array_intersect_key($block, array_flip(['id', 'type', 'text', 'items', 'headers', 'rows', 'level'])), $section['blocks'])], $assembled['sections']),
            'media' => $editor ? $manifest['media'] : array_values(array_filter($manifest['media'], fn (array $item): bool => in_array($item['id'], $visibleMedia, true)))];
    }

    public function baseToken(Lesson $lesson, array $document, string $profileKey): string
    {
        return hash_hmac('sha256', $this->canonicalJson(['profile_key' => $profileKey, 'lesson_id' => $lesson->id, 'source_sha' => $lesson->sha256, 'document' => $document]), (string) config('app.key'));
    }

    public function revisionFingerprint(Lesson $lesson): string
    {
        $settings = ContentSetting::where('lesson_id', $lesson->id)->orderBy('kind')->orderBy('item_id')->lockForUpdate()->get(['kind', 'item_id', 'enabled', 'deleted', 'position'])->toArray();

        return hash('sha256', $this->canonicalJson(['source_sha' => $lesson->sha256, 'document' => $this->document($lesson), 'settings' => $settings]));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalValue($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
    }

    public function review(array $data): array
    {
        $base = isset($data['base_id']) ? Lesson::findOrFail($data['base_id']) : null;
        if ($base) {
            abort_unless(hash_equals($base->sha256, $data['base_sha'] ?? ''), 409, 'The downloaded base checksum does not match.');
            abort_unless(is_array($data['base_document'] ?? null) && is_string($data['base_token'] ?? null), 422, 'Existing curriculum edits require the downloaded base document and signature.');
            abort_unless(hash_equals($this->baseToken($base, $data['base_document'], $data['profile_key']), $data['base_token']), 409, 'The downloaded base signature is invalid. Download this curriculum again.');
        }
        $family = $base?->manifest_id ?? $data['document']['manifest_id'];
        abort_unless(! $base || $data['document']['manifest_id'] === $family, 422, 'The document family cannot change. Use Save as copy.');
        $head = ($data['as_copy'] ?? false) ? null : Lesson::where('manifest_id', $family)->latest('id')->first();
        if (! $base && $head) {
            abort(409, 'This curriculum already exists. Download it before editing.');
        }
        $merge = app(ThreeWayMerge::class)->merge($base ? $data['base_document'] : null, $data['document'], $head ? $this->document($head) : null, $data['resolutions'] ?? []);
        if (($data['as_copy'] ?? false)) {
            $merge = ['document' => $data['document'], 'conflicts' => []];
        }

        return ['head' => $head ? ['id' => $head->id, 'sha' => $this->revisionFingerprint($head)] : null, ...$merge];
    }

    public function save(?Lesson $original, array $document, string $version, bool $asCopy, array $uploads = []): Lesson
    {
        Validator::make($document, [
            'manifest_id' => 'required|string|max:180|regex:/^[A-Za-z0-9_.:-]+$/', 'title' => 'required|string|max:255', 'subject' => 'required|string|max:255', 'form' => 'required', 'language' => 'required|string|max:30', 'overview' => 'present|array',
            'sections' => 'required|array|min:1|max:100', 'sections.*.id' => 'required|string|distinct|regex:/^[A-Za-z0-9_.:-]{1,180}$/', 'sections.*.title' => 'required|string|max:255', 'sections.*.criterion_ids' => 'required|array|min:1', 'sections.*.criterion_ids.*' => 'required|string|max:180',
            'sections.*.blocks' => 'required|array|min:1|max:500', 'sections.*.blocks.*.id' => 'required|string|distinct|regex:/^[A-Za-z0-9_.:-]{1,180}$/', 'sections.*.blocks.*.type' => 'required|in:paragraph,heading,list,table', 'media' => 'present|array|max:100',
        ])->validate();
        $source = $original ? $this->assembler->source($original) : null;
        $manifest = $source['manifest'] ?? $this->draftManifest();
        $manifest['lesson_id'] = $asCopy ? 'offline-'.Str::uuid() : $document['manifest_id'];
        $manifest['lesson_version'] = $version;
        $manifest['title'] = $document['title'];
        $manifest['language'] = $document['language'];
        $manifest['syllabus']['subject'] = $document['subject'];
        $manifest['syllabus']['form'] = $document['form'];
        $manifest['overview'] = $document['overview'];
        $manifest['media'] = $document['media'];
        $manifest['sections'] = [];
        $replacements = [];
        foreach ($document['sections'] as $index => $section) {
            $path = 'content/offline-'.$index.'.json';
            $blocks = [];
            foreach ($section['blocks'] as $position => $block) {
                $blocks[] = [...array_intersect_key($block, array_flip(['id', 'type', 'text', 'items', 'headers', 'rows', 'level'])), 'order' => $position + 1];
            }
            $manifest['sections'][] = ['id' => $section['id'], 'title' => $section['title'], 'order' => $index + 1, 'criterion_ids' => $section['criterion_ids'], 'block_ids' => array_column($blocks, 'id'), 'content_path' => $path];
            $replacements['dist/'.$path] = ['id' => $section['id'], 'title' => $section['title'], 'blocks' => $blocks];
        }
        $temporary = $original ? app(CurriculumPackages::class)->export($original) : tempnam(sys_get_temp_dir(), 'nthanda-offline-');
        if ($original) {
            $known = ['section' => array_column($manifest['sections'], 'id'), 'block' => array_merge(...array_column($manifest['sections'], 'block_ids')), 'media' => array_column($manifest['media'], 'id')];
            $known['media'] = [...$known['media'], ...array_column($source['compatibility']['additional_media'] ?? [], 'id')];
            $settings = ContentSetting::where('lesson_id', $original->id)->get(['kind', 'item_id', 'enabled', 'deleted', 'position'])->filter(fn (ContentSetting $setting): bool => in_array($setting->item_id, $known[$setting->kind], true))->toArray();
            $positions = array_column($manifest['sections'], 'order', 'id');
            foreach ($settings as &$setting) {
                if ($setting['kind'] === 'section') {
                    $setting['position'] = $positions[$setting['item_id']];
                }
            }
            unset($setting);
            $replacements['studio-settings.json'] = array_values($settings);
        }
        $zip = new ZipArchive;
        try {
            $this->parser->check($zip->open($temporary, $original ? 0 : ZipArchive::OVERWRITE) === true, 'Unable to create curriculum package.');
            if (! $original) {
                $replacements['source.txt'] = ['text' => 'Offline authored draft'];
                $sourceBytes = json_encode($replacements['source.txt'], JSON_THROW_ON_ERROR);
                $manifest['source'] = ['path' => 'source.txt', 'version' => $version, 'sha256' => hash('sha256', $sourceBytes)];
                $replacements['assessments/marking.json'] = ['questions' => [], 'rules' => []];
                $replacements['assessments/rubric.json'] = [];
                $replacements['dist/written.json'] = ['text' => []];
            }
            foreach ($manifest['media'] as &$media) {
                $id = $media['id'] ?? '';
                $upload = $uploads[$id] ?? null;
                if ($upload) {
                    $this->parser->check($upload instanceof UploadedFile && $upload->isValid() && $upload->getSize() <= 64 * 1024 * 1024, 'Media uploads must be valid files at most 64 MiB.');
                    $mime = $upload->getMimeType();
                    $extension = ['image/webp' => 'webp', 'audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg', 'image/svg+xml' => 'svg', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'video/mp4' => 'mp4'][$mime] ?? null;
                    $this->parser->check($extension !== null, 'Unsupported uploaded media type.');
                    $this->parser->check(preg_match('/^[A-Za-z0-9_.:-]{1,180}$/D', $id) === 1, 'Uploaded media IDs must be safe stable content IDs.');
                    $this->parser->check(is_string($media['sha256'] ?? null) && hash_equals(hash_file('sha256', $upload->getRealPath()), $media['sha256']), 'Uploaded media SHA-256 does not match.');
                    $this->parser->check(isset($media['bytes']) && (int) $media['bytes'] === $upload->getSize(), 'Uploaded media byte count does not match.');
                    $this->parser->check((str_starts_with($mime, 'image/') && ($media['type'] ?? null) === 'image') || (str_starts_with($mime, 'audio/') && in_array($media['type'] ?? null, ['audio', 'pronunciation'], true)) || (in_array($mime, ['video/mp4', 'video/webm'], true) && ($media['type'] ?? null) === 'video'), 'Uploaded media type does not match its content.');
                    if ($extension === 'svg') {
                        $this->validateSvg(file_get_contents($upload->getRealPath()));
                    }
                    $safeId = preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id) ? $id : hash('sha256', $id);
                    $media['path'] = 'media/offline/'.$safeId.'.'.$extension;
                    $zip->addFile($upload->getRealPath(), 'dist/'.$media['path']);
                    $media += ['required' => true, 'production_status' => 'produced', 'review_status' => 'pending', 'reviewer' => null, 'approved_source_version' => null];
                } else {
                    $known = collect($source['manifest']['media'] ?? [])->firstWhere('id', $id);
                    $this->parser->check($known !== null && ($known['path'] ?? null) === ($media['path'] ?? null), 'New media requires a verified uploaded file.');
                }
                foreach (['captions' => 'vtt', 'transcript' => 'txt'] as $kind => $extension) {
                    $auxiliary = $uploads[$id.':'.$kind] ?? null;
                    if (! $auxiliary) {
                        continue;
                    }
                    $this->parser->check($auxiliary instanceof UploadedFile && $auxiliary->isValid() && $auxiliary->getSize() <= 2 * 1024 * 1024, 'Captions and transcripts must be valid files at most 2 MiB.');
                    $bytes = file_get_contents($auxiliary->getRealPath());
                    $this->parser->check(mb_check_encoding($bytes, 'UTF-8') && ! str_contains($bytes, chr(0)) && ! preg_match('/<\s*(script|iframe|object|embed|html|svg)\b/i', $bytes), 'Captions and transcripts must contain safe UTF-8 text.');
                    $this->parser->check($kind !== 'captions' || str_starts_with(ltrim($bytes, chr(239).chr(187).chr(191)), 'WEBVTT'), 'Captions must use WEBVTT format.');
                    $this->parser->check(is_string($media['accessibility'][$kind.'_sha256'] ?? null) && hash_equals(hash('sha256', $bytes), $media['accessibility'][$kind.'_sha256']), 'Caption or transcript SHA-256 does not match.');
                    $this->parser->check((int) ($media['accessibility'][$kind.'_bytes'] ?? -1) === strlen($bytes), 'Caption or transcript byte count does not match.');
                    $auxPath = 'media/offline/'.hash('sha256', $id).'-'.$kind.'.'.$extension;
                    $zip->addFromString('dist/'.$auxPath, $bytes);
                    $media['accessibility'][$kind.'_path'] = $auxPath;
                }
            }
            unset($media);
            $replacements['lesson-manifest.json'] = $manifest;
            foreach ($replacements as $path => $value) {
                $zip->addFromString($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
            $zip->close();

            return app(PackageImporter::class)->import($temporary)['lesson'];
        } finally {
            unset($zip);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function validateSvg(string $bytes): void
    {
        $this->parser->check(! preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes), 'SVG declarations and entities are not allowed.');
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $valid = $document->loadXML($bytes, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $this->parser->check($valid && $document->documentElement?->localName === 'svg', 'Invalid SVG image.');
        foreach ($document->getElementsByTagName('*') as $element) {
            $this->parser->check(! in_array(strtolower($element->localName), ['script', 'foreignobject', 'iframe', 'object', 'embed', 'animate', 'animatetransform', 'animatemotion', 'set'], true), 'SVG must be a static image without active content.');
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->localName);
                $value = trim($attribute->value);
                $this->parser->check(! str_starts_with($name, 'on') && ($name !== 'href' || str_starts_with($value, '#')) && ! preg_match('/url\s*\(|@import|expression\s*\(/i', $value), 'SVG external references and active attributes are not allowed.');
            }
            if (strtolower($element->localName) === 'style') {
                $this->parser->check(! preg_match('/url\s*\(|@import|expression\s*\(/i', $element->textContent), 'SVG external styles are not allowed.');
            }
        }
    }

    private function draftManifest(): array
    {
        return ['schema_version' => 'nthanda-pilot-2.0', 'syllabus' => ['curriculum_id' => 'offline-draft', 'edition' => 'draft', 'subject' => '', 'form' => '', 'printed_pages' => []],
            'tracking' => ['learner_timer_visible' => false, 'sections' => true, 'lesson_total' => true, 'storage' => 'server', 'idle_pause_seconds' => 60],
            'alternative_explanations' => [], 'teaching_links' => [], 'assessment_links' => [],
            'assessments' => ['learner_written_path' => 'written.json', 'protected_marking_reference' => 'assessments/marking.json', 'protected_source_rubric_reference' => 'assessments/rubric.json', 'automatic_policy' => 'No automatic assessment in this draft.', 'original_written_policy' => 'Teacher review required.'],
            'review' => ['status' => 'pending', 'reviewer' => null, 'approved_source_version' => null], 'publication' => ['status' => 'draft', 'blockers' => ['Teacher review required before publication.']]];
    }
}
