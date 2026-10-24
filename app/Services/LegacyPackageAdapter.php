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

        return $metadata;
    }
}
