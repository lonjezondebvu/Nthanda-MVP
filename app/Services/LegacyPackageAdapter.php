<?php

namespace App\Services;

class LegacyPackageAdapter
{
    public function adapt(string $root, array $manifest, ManifestParser $parser): array
    {
        $metadata = ['activities' => [], 'overview' => [], 'additional_media' => [], 'adapter' => 'nthanda-pilot-2.0/v1'];
        if (is_file($root.'/dist/data.js')) {
            $text = trim(file_get_contents($root.'/dist/data.js'));
            $parser->check(str_starts_with($text, 'window.NTHANDA_DATA='), 'Legacy data wrapper is not recognized; it will not be executed.');
            try {
                $data = json_decode(rtrim(substr($text, strlen('window.NTHANDA_DATA=')), ";\r\n "), true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $parser->check(false, 'Legacy data must be a literal JSON assignment.');
            }
            $metadata['activities'] = $data['questions'] ?? [];
            foreach ($metadata['activities'] as $activity) {
                $parser->check(is_array($activity) && is_string($activity['id'] ?? null) && is_string($activity['title'] ?? null) && is_string($activity['feedback'] ?? null) && is_array($activity['prompt_parts'] ?? null), 'Malformed formative activity.');
                foreach ($activity['prompt_parts'] as $part) {
                    $parser->check(is_array($part) && is_string($part['part_id'] ?? null) && is_string($part['text'] ?? null), 'Malformed formative activity prompt.');
                }
                $parser->check(($activity['purpose'] ?? null) === 'formative' && ($activity['counts_for_mastery'] ?? true) === false, 'Unsupported legacy activity policy.');
                foreach ($activity['section_ids'] ?? [] as $id) {
                    $parser->check(in_array($id, array_column($manifest['sections'], 'id')), 'Unknown activity section.');
                }
            }
            // Explicit, versioned compatibility mapping of the standalone player's omitted quick check.
            if ($manifest['lesson_id'] === 'JCE-BIO-U10' && $manifest['lesson_version'] === '2.1.0-pilot') {
                $metadata['overview'] = [
                    'summary' => 'Discover how leaves, stems and roots work together, and how plants make food.',
                    'introduction' => [
                        'Plants need water, mineral salts and food to grow. Roots absorb water and mineral salts, stems connect the parts of the plant, and green leaves make food. These parts work together: a leaf cannot keep making food if it has no water supply.',
                        'This unit builds on your knowledge of plant parts. It explains their external and internal structures, then shows how those structures support photosynthesis. Plants provide food and oxygen and are important in agriculture and the environment.',
                    ],
                    'goals' => ['Describe structure and functions of plants', 'Describe the process of photosynthesis'],
                    'practical_guidance' => 'For observation activities, use a common plant supplied by your teacher, a potted seedling or clear photographs. Practical work with hot water, ethanol or chemicals must be supervised by a teacher.',
                ];
                $photos = array_values(array_filter($data['media_variants'] ?? [], fn ($v) => str_starts_with($v['title'] ?? '', 'ChatGPT Image')));
                foreach ($photos as $index => $photo) {
                    $metadata['additional_media'][] = [
                        'id' => 'JCE-BIO-U10-S002-legacy-image-'.($index + 1), 'type' => 'image', 'path' => $photo['path'],
                        'section_id' => 'JCE-BIO-U10-S002', 'anchor_block_id' => 'JCE-BIO-U10-S002-B003', 'placement' => 'after', 'required' => true,
                        'accessibility' => ['description' => 'Supplied plant illustration for observing visible plant parts'],
                    ];
                }
                $metadata['activities'][] = [
                    'id' => 'JCE-BIO-U10-P001', 'title' => 'Shoot system quick check', 'purpose' => 'formative', 'counts_for_mastery' => false,
                    'section_ids' => ['JCE-BIO-U10-S001'], 'kind' => 'mc',
                    'prompt' => 'Which group of plant parts belongs to the shoot system?',
                    'options' => ['Stem, leaves, buds, flowers and fruits', 'Main root, lateral roots and root hairs', 'Only roots and flowers', 'Only the parts below the soil'],
                    'feedback' => 'The shoot system consists of the stem and the parts it bears, including leaves, buds, flowers and fruits. Roots form the root system.',
                ];
            }
        }

        if (is_file($root.'/studio-compatibility.json')) {
            $metadata = $parser->json($root.'/studio-compatibility.json');
            $parser->check(is_array($metadata['activities'] ?? null) && array_is_list($metadata['activities']) && is_array($metadata['overview'] ?? null) && is_array($metadata['additional_media'] ?? null) && array_is_list($metadata['additional_media']), 'Invalid exported curriculum metadata.');
            $activityIds = [];
            foreach ($metadata['activities'] as $activity) {
                $parser->check(is_array($activity) && is_string($activity['id'] ?? null) && is_string($activity['title'] ?? null) && is_string($activity['feedback'] ?? null) && is_array($activity['section_ids'] ?? null) && ($activity['purpose'] ?? null) === 'formative' && ($activity['counts_for_mastery'] ?? true) === false, 'Invalid exported practice activity.');
                $parser->check(preg_match('/^[A-Za-z0-9_.:-]{1,180}$/D', $activity['id']) === 1 && ! in_array($activity['id'], $activityIds, true), 'Duplicate or invalid exported practice ID.');
                $activityIds[] = $activity['id'];
                foreach ($activity['section_ids'] as $id) {
                    $parser->check(in_array($id, array_column($manifest['sections'], 'id'), true), 'Unknown exported activity section.');
                }
                $parser->check(! isset($activity['prompt_parts']) || (is_array($activity['prompt_parts']) && array_is_list($activity['prompt_parts'])), 'Invalid exported activity prompt parts.');
                foreach ($activity['prompt_parts'] ?? [] as $part) {
                    $parser->check(is_string($part['part_id'] ?? null) && is_string($part['text'] ?? null), 'Invalid exported activity prompt.');
                }
                if (isset($activity['options'])) {
                    $parser->check($parser->strings($activity['options']), 'Invalid exported activity options.');
                }
                $parser->check(! isset($activity['prompt']) || is_string($activity['prompt']), 'Invalid exported activity text.');
            }
            $mediaIds = array_merge(array_column($manifest['media'], 'id'), array_column($manifest['sections'], 'id'), ...array_column($manifest['sections'], 'block_ids'));
            foreach ($metadata['additional_media'] as &$item) {
                $parser->check(is_array($item), 'Exported media must be an object.');
                $parser->check(! isset($item['references']), 'Exported compatibility media must use its single section and block anchor.');
                $section = collect($manifest['sections'])->firstWhere('id', $item['section_id'] ?? null);
                $parser->check($section !== null && in_array($item['anchor_block_id'] ?? null, $section['block_ids'], true), 'Unknown exported media anchor.');
                $parser->check(is_string($item['id'] ?? null) && in_array($item['type'] ?? null, ['image', 'audio', 'video', 'animation', 'pronunciation'], true) && is_string($item['path'] ?? null) && in_array($item['placement'] ?? null, ['before', 'after', 'inline-after-word'], true), 'Invalid exported media.');
                $parser->check(is_string($item['accessibility']['description'] ?? ''), 'Invalid exported media description.');
                $parser->check(! isset($item['term']) || is_string($item['term']), 'Invalid exported pronunciation term.');
                $parser->check(! isset($item['aliases']) || $parser->strings($item['aliases']), 'Invalid exported pronunciation aliases.');
                $parser->check(preg_match('/^[A-Za-z0-9_.:-]{1,180}$/D', $item['id']) === 1 && ! in_array($item['id'], $mediaIds, true), 'Duplicate or invalid exported media ID.');
                $mediaIds[] = $item['id'];
                $item = array_intersect_key($item, array_flip(['id', 'type', 'path', 'section_id', 'anchor_block_id', 'placement', 'required', 'accessibility', 'term', 'aliases']));
            }
            unset($item);
            $metadata['adapter'] = 'studio-export/v1';
        }
        $overview = $manifest['overview'] ?? $metadata['overview'];
        $parser->check(is_array($overview), 'Curriculum overview must be an object.');
        foreach (['summary', 'practical_guidance'] as $key) {
            $parser->check(! isset($overview[$key]) || is_string($overview[$key]), 'Curriculum overview text must be a string.');
        }
        foreach (['introduction', 'goals'] as $key) {
            $parser->check(! isset($overview[$key]) || $parser->strings($overview[$key]), 'Curriculum overview items must be strings.');
        }
        $metadata['overview'] = $overview;

        return $metadata;
    }
}
