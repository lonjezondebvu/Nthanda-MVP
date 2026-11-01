<?php

namespace App\Services;

use App\Models\ContentSetting;
use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

class LessonAssembler
{
    public function source(Lesson $lesson): array
    {
        $path = $lesson->directory.'/derived/lesson.json';
        $contents = Storage::disk('local')->get($path);
        if (! is_string($contents)) {
            throw UnableToReadFile::fromLocation($path);
        }

        return json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
    }

    public function assemble(Lesson $lesson, bool $admin = false): array
    {
        $source = $this->source($lesson);
        $manifest = $source['manifest'];
        $settings = ContentSetting::where('lesson_id', $lesson->id)->get()->keyBy(fn ($s) => $s->kind.':'.$s->item_id);
        $state = function (string $kind, string $id) use ($settings) {
            $s = $settings->get($kind.':'.$id);

            return ['enabled' => $s?->enabled ?? true, 'deleted' => $s?->deleted ?? false, 'position' => $s?->position];
        };
        $available = fn ($s) => $s['enabled'] && ! $s['deleted'];
        $sections = [];
        $flags = [];
        $activeAssets = [];
        foreach ($source['sections'] as $section) {
            $section['state'] = $state('section', $section['id']);
            $section['order'] = $section['state']['position'] ?? $section['order'];
            if (! $available($section['state'])) {
                $flags[] = 'Unavailable section '.$section['id'].' affects criteria '.implode(', ', $section['criterion_ids']).'. Assessment requirements are unchanged.';
            }
            if (! $admin && ! $available($section['state'])) {
                continue;
            }
            $blocks = [];
            foreach ($section['blocks'] as $block) {
                $block['state'] = $state('block', $block['id']);
                if (! $available($block['state'])) {
                    $flags[] = 'Hidden block '.$block['id'].' needs assessment and anchored media review.';
                }
                if ($admin || $available($block['state'])) {
                    $blocks[] = $block;
                }
            }
            usort($blocks, fn ($a, $b) => $a['order'] <=> $b['order']);
            $section['blocks'] = $blocks;
            $section['media'] = [];
            foreach ($source['media'] as $item) {
                $refs = $item['references'] ?? (isset($item['section_id']) ? [$item] : []);
                foreach ($refs as $ref) {
                    if ($ref['section_id'] !== $section['id']) {
                        continue;
                    }
                    $itemState = $state('media', $item['id']);
                    foreach ($item['chunk_ids'] ?? [] as $id) {
                        if (! $available($state('media', $id))) {
                            $itemState['enabled'] = false;
                        }
                    }
                    if (! $available($itemState)) {
                        $flags[] = 'Hidden media '.$item['id'].' in '.$section['id'].' needs review.';
                    }
                    if (! $admin && ! $available($itemState)) {
                        continue;
                    }
                    $assetUrl = fn ($key) => '/lessons/'.$lesson->id.'/assets/'.$key;
                    $view = ['id' => $item['id'], 'type' => $item['type'], 'term' => $item['term'] ?? null, 'aliases' => $item['aliases'] ?? [], 'anchor_word' => $ref['anchor_word'] ?? $item['term'] ?? null, 'anchor_block_id' => $ref['anchor_block_id'], 'placement' => $ref['placement'],
                        'description' => $item['accessibility']['description'] ?? $item['term'] ?? $item['id'], 'state' => $itemState,
                        'url' => $assetUrl($item['asset_key']), 'captions_url' => isset($item['captions_key']) ? $assetUrl($item['captions_key']) : null,
                        'transcript_url' => isset($item['transcript_key']) ? $assetUrl($item['transcript_key']) : null];
                    $section['media'][] = $view;
                    if ($available($section['state']) && $available($itemState)) {
                        foreach (['asset_key', 'captions_key', 'transcript_key'] as $key) {
                            if (isset($item[$key])) {
                                $activeAssets[$item[$key]] = $source['assets'][$item[$key]];
                            }
                        }
                    }
                }
            }
            $section['alternatives'] = array_values(array_filter($manifest['alternative_explanations'], fn ($a) => in_array($section['id'], $a['parent_section_ids'])));
            $section['activities'] = array_values(array_filter($source['compatibility']['activities'], fn ($a) => in_array($section['id'], $a['section_ids'] ?? [])));
            $sections[] = $section;
        }
        usort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);
        $logoKey = hash('sha256', 'dist/media/branding/nthanda-logo.png');
        if (isset($source['assets'][$logoKey])) {
            $activeAssets[$logoKey] = $source['assets'][$logoKey];
        }
        $private = $this->marking($lesson);
        $questions = [];
        foreach ($private['questions'] as $q) {
            $safe = array_intersect_key($q, array_flip(['id', 'criterionId', 'kind', 'domain', 'prompt', 'options', 'sectionIds', 'maxMarks']));
            // Unpaired terms/options are question material; never serialize the correct pair mapping/order.
            if ($q['kind'] === 'match') {
                $safe['matchKeys'] = array_keys($q['answer']);
                $safe['matchOptions'] = array_values($q['answer']);
                sort($safe['matchKeys']);
                sort($safe['matchOptions']);
            }
            if ($q['kind'] === 'order') {
                $safe['items'] = $q['answer'];
                sort($safe['items']);
            }
            $questions[] = $safe;
        }

        return ['id' => $lesson->id, 'manifest_id' => $lesson->manifest_id, 'version' => $lesson->version, 'title' => $lesson->title, 'syllabus' => $manifest['syllabus'],
            'publication' => app(PublicationReview::class)->isCurrent($lesson) ? ['status' => 'published', 'blockers' => []] : [...$manifest['publication'], 'status' => $manifest['publication']['status'] === 'published' ? 'preview-only' : $manifest['publication']['status']], 'overview' => $source['compatibility']['overview'], 'sections' => $sections, 'questions' => $questions, 'written' => $source['written'],
            'policies' => ['automatic' => $manifest['assessments']['automatic_policy'], 'written' => $manifest['assessments']['original_written_policy']],
            'logo_url' => isset($source['assets'][$logoKey]) ? '/lessons/'.$lesson->id.'/assets/'.$logoKey : null,
            'active_count' => count(array_filter($sections, fn ($s) => $available($s['state']))), 'assets' => $activeAssets,
            'review_flags' => $admin ? array_values(array_unique($flags)) : []];
    }

    public function marking(Lesson $lesson): array
    {
        $source = $this->source($lesson);

        $path = $lesson->directory.'/source/'.$source['manifest']['assessments']['protected_marking_reference'];
        $contents = Storage::disk('local')->get($path);
        if (! is_string($contents)) {
            throw UnableToReadFile::fromLocation($path);
        }

        return json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
    }
}
