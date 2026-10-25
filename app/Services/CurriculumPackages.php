<?php

namespace App\Services;

use App\Models\ContentSetting;
use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class CurriculumPackages
{
    public function __construct(private LessonAssembler $assembler) {}

    /** @param array{title: string, version: string, sections: array} $data */
    public function createVersion(Lesson $lesson, array $data): Lesson
    {
        if (Lesson::where('manifest_id', $lesson->manifest_id)->where('version', $data['version'])->exists()) {
            throw ValidationException::withMessages(['version' => 'Choose a new version number. This version already exists.']);
        }
        $source = $this->assembler->source($lesson);
        $knownSections = collect($source['sections'])->keyBy('id');
        $ids = array_column($data['sections'], 'id');
        if (count($ids) !== $knownSections->count() || count(array_unique($ids)) !== count($ids) || array_diff($ids, $knownSections->keys()->all())) {
            throw ValidationException::withMessages(['sections' => 'Include every original section exactly once.']);
        }
        $manifest = $source['manifest'];
        $manifest['title'] = $data['title'];
        $manifest['lesson_version'] = $data['version'];
        $manifestSections = collect($manifest['sections'])->keyBy('id');
        $manifest['sections'] = [];
        $replacements = [];
        foreach ($data['sections'] as $position => $section) {
            $original = $knownSections[$section['id']];
            $knownBlocks = collect($original['blocks'])->keyBy('id');
            $blockIds = array_column($section['blocks'], 'id');
            if (count($blockIds) !== $knownBlocks->count() || count(array_unique($blockIds)) !== count($blockIds) || array_diff($blockIds, $knownBlocks->keys()->all())) {
                throw ValidationException::withMessages(['sections' => 'Keep every original block ID when editing.']);
            }
            $blocks = [];
            foreach ($section['blocks'] as $index => $block) {
                $existing = $knownBlocks[$block['id']];
                if ($existing['type'] !== $block['type']) {
                    throw ValidationException::withMessages(['sections' => 'Block types cannot be changed.']);
                }
                $changes = array_intersect_key($block, array_flip(['text', 'items', 'headers', 'rows']));
                if (array_key_exists('text', $changes)) {
                    $changes['text'] = (string) $changes['text'];
                }
                foreach (['items', 'headers'] as $key) {
                    if (isset($changes[$key])) {
                        $changes[$key] = array_map('strval', $changes[$key]);
                    }
                }
                if (isset($changes['rows'])) {
                    $changes['rows'] = array_map(fn (array $row): array => array_map('strval', $row), $changes['rows']);
                }
                $blocks[] = [...$existing, ...$changes, 'order' => $index + 1];
            }
            $entry = $manifestSections[$section['id']];
            $entry['title'] = $section['title'];
            $entry['order'] = $position + 1;
            $manifest['sections'][] = $entry;
            $content = json_decode(Storage::disk('local')->get($lesson->directory.'/source/dist/'.$entry['content_path']), true, 128, JSON_THROW_ON_ERROR);
            $content['blocks'] = $blocks;
            $content['title'] = $section['title'];
            $replacements['dist/'.$entry['content_path']] = $content;
        }
        $replacements['lesson-manifest.json'] = $manifest;
        $temporary = $this->export($lesson, $replacements);
        try {
            return app(PackageImporter::class)->import($temporary)['lesson'];
        } finally {
            unlink($temporary);
        }
    }

    /** @param array<string, array> $replacements */
    public function export(Lesson $lesson, array $replacements = []): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'nthanda-export-');
        if ($temporary === false || ! copy(Storage::disk('local')->path($lesson->directory.'/original.zip'), $temporary)) {
            throw new \RuntimeException('Unable to prepare curriculum ZIP.');
        }
        $zip = new ZipArchive;
        try {
            if ($zip->open($temporary) !== true) {
                throw new \RuntimeException('Unable to open curriculum ZIP.');
            }
            foreach ($replacements as $path => $content) {
                if (! $zip->addFromString($path, json_encode($content, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                    throw new \RuntimeException('Unable to update curriculum ZIP.');
                }
            }
            $compatibility = $this->assembler->source($lesson)['compatibility'];
            $zip->addFromString('studio-compatibility.json', json_encode($compatibility, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $settings = ContentSetting::where('lesson_id', $lesson->id)->get(['kind', 'item_id', 'enabled', 'deleted', 'position'])->toArray();
            if (isset($replacements['lesson-manifest.json'])) {
                $positions = array_column($replacements['lesson-manifest.json']['sections'], 'order', 'id');
                foreach ($settings as &$setting) {
                    if ($setting['kind'] === 'section') {
                        $setting['position'] = $positions[$setting['item_id']];
                    }
                }
                unset($setting);
            }
            $zip->addFromString('studio-settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
            if (! $zip->close()) {
                throw new \RuntimeException('Unable to finish curriculum ZIP.');
            }

            return $temporary;
        } catch (\Throwable $exception) {
            unset($zip);
            unlink($temporary);
            throw $exception;
        }
    }

    public function restoreSettings(Lesson $lesson, string $source): void
    {
        if (! is_file($source.'/studio-settings.json')) {
            return;
        }
        $settings = app(ManifestParser::class)->json($source.'/studio-settings.json');
        Validator::make(['settings' => $settings], [
            'settings' => 'present|array|max:3000',
            'settings.*.kind' => 'required|in:section,block,media',
            'settings.*.item_id' => 'required|string|max:180',
            'settings.*.enabled' => 'required|boolean',
            'settings.*.deleted' => 'required|boolean',
            'settings.*.position' => 'nullable|integer|min:1|max:1500',
        ])->validate();
        $data = $this->assembler->source($lesson);
        $known = [
            'section' => array_column($data['sections'], 'id'),
            'block' => array_merge(...array_map(fn (array $section): array => array_column($section['blocks'], 'id'), $data['sections'])),
            'media' => array_column($data['media'], 'id'),
        ];
        foreach ($settings as $setting) {
            if (! in_array($setting['item_id'], $known[$setting['kind']], true)) {
                throw ValidationException::withMessages(['package' => 'The exported settings reference unknown curriculum content.']);
            }
            ContentSetting::updateOrCreate(['lesson_id' => $lesson->id, 'kind' => $setting['kind'], 'item_id' => $setting['item_id']], array_intersect_key($setting, array_flip(['enabled', 'deleted', 'position'])));
        }
    }
}
