<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Validator;

class ManifestParser
{
    public function parse(string $root): array
    {
        $raw = $this->json($root.'/lesson-manifest.json');
        $this->check(($raw['schema_version'] ?? null) === 'nthanda-pilot-2.0', 'Unsupported manifest schema version. Supported: nthanda-pilot-2.0.');
        // The compatibility schema is application-owned; package schemas are never trusted as validators.
        $schema = json_decode(file_get_contents(resource_path('schemas/nthanda-pilot-2.0.json')));
        $result = (new Validator)->validate(json_decode(json_encode($raw)), $schema);
        $this->check($result->isValid(), 'The manifest does not conform to the supported pilot schema.');
        $sourceFile = $root.'/'.$this->safePath($raw['source']['path']);
        $this->check(is_file($sourceFile) && hash_file('sha256', $sourceFile) === $raw['source']['sha256'], 'Source document is missing or its SHA-256 does not match the manifest.');
        $ids = [];
        $claim = function ($id) use (&$ids) {
            $this->check(is_string($id) && preg_match('/^[A-Za-z0-9_.:-]{1,180}$/D', $id) && ! isset($ids[$id]), 'IDs must be nonempty, safe and unique: '.(is_scalar($id) ? $id : 'invalid'));
            $ids[$id] = true;
        };
        foreach ($raw['sections'] as $section) {
            $claim($section['id']);
            $content = $this->json($root.'/dist/'.$this->safePath($section['content_path']));
            $this->check(($content['id'] ?? null) === $section['id'], 'Content identity does not match section '.$section['id']);
            $this->check(is_array($content['blocks'] ?? null), 'Section blocks must be an array.');
            foreach ($content['blocks'] as $block) {
                $this->check(is_array($block), 'Every content block must be an object.');
                $claim($block['id'] ?? null);
                $this->check(in_array($block['type'] ?? '', ['heading', 'paragraph', 'list', 'table']), 'Unsupported content block type.');
                $this->check(is_int($block['order'] ?? null), 'Content block order must be an integer.');
                if (in_array($block['type'], ['heading', 'paragraph'])) {
                    $this->check(is_string($block['text'] ?? null), 'Heading and paragraph text must be strings.');
                }
                if ($block['type'] === 'list') {
                    $this->check($this->strings($block['items'] ?? null), 'List items must be strings.');
                }
                if ($block['type'] === 'table') {
                    $this->check($this->strings($block['headers'] ?? null) && count($block['headers']) > 0 && is_array($block['rows'] ?? null) && array_is_list($block['rows']), 'Table headers and rows must be arrays.');
                    foreach ($block['rows'] as $row) {
                        $this->check($this->strings($row) && count($row) === count($block['headers']), 'Table row cells must be strings matching the header count.');
                    }
                }
            }
            $actual = array_column($content['blocks'], 'id');
            $expected = $section['block_ids'];
            sort($actual);
            sort($expected);
            $this->check($actual === $expected, 'Section block references do not match content.');
        }
        $sections = array_column($raw['sections'], 'id');
        foreach ($raw['media'] as $media) {
            $claim($media['id']);
            if (isset($media['term'])) {
                $this->check(is_string($media['term']), 'Pronunciation term must be a string.');
            }
            if (isset($media['aliases'])) {
                $this->check($this->strings($media['aliases']), 'Pronunciation aliases must be strings.');
            }
            if (isset($media['accessibility']['description'])) {
                $this->check(is_string($media['accessibility']['description']), 'Media description must be a string.');
            }
            $refs = $media['references'] ?? (isset($media['section_id']) ? [$media] : []);
            foreach ($refs as $ref) {
                $this->check(in_array($ref['section_id'] ?? null, $sections), 'Unknown media section reference.');
                $section = collect($raw['sections'])->firstWhere('id', $ref['section_id']);
                $this->check(in_array($ref['anchor_block_id'] ?? null, $section['block_ids']), 'Unknown media anchor block.');
                $this->check(in_array($ref['placement'] ?? 'after', ['before', 'after', 'inline-after-word']), 'Unsupported media placement.');
            }
        }
        foreach ($raw['alternative_explanations'] as $alt) {
            $claim($alt['id']);
            $this->check(is_string($alt['text'] ?? null) && (! isset($alt['title']) || is_string($alt['title'])), 'Alternative explanation text must be a string.');
            foreach ($alt['parent_section_ids'] as $id) {
                $this->check(in_array($id, $sections), 'Unknown alternative section.');
            }
            foreach ($alt['source_block_ids'] as $id) {
                $this->check(isset($ids[$id]), 'Unknown alternative block.');
            }
        }
        foreach ($raw['assessment_links'] as $link) {
            foreach ($link['sections'] as $id) {
                $this->check(in_array($id, $sections), 'Unknown assessment section.');
            }
        }
        foreach ($raw['teaching_links'] as $link) {
            $this->check(in_array($link['section_id'], $sections), 'Unknown teaching section.');
        }
        foreach (['protected_marking_reference', 'protected_source_rubric_reference'] as $key) {
            $path = $this->safePath($raw['assessments'][$key]);
            $this->check(str_starts_with($path, 'assessments/') && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json', 'Protected assessment references must be JSON files inside the private assessments directory, outside dist/.');
        }

        return $raw;
    }

    public function safePath(string $path): string
    {
        $this->check($path !== '' && strlen($path) < 400 && ! preg_match('/[\\\\:\x00-\x1f?#<>"|*]/', $path) && ! str_starts_with($path, '/') && ! in_array('..', explode('/', $path)) && ! in_array('.', explode('/', $path)), 'Unsafe archive or referenced path.');
        foreach (explode('/', $path) as $component) {
            $this->check($component !== '' && rtrim($component, ' .') === $component && ! preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/iD', $component), 'Unsafe or aliased Windows path component.');
        }

        return $path;
    }

    public function json(string $path): array
    {
        $this->check(is_file($path), 'Missing required file: '.basename($path));
        $this->check(filesize($path) <= 2 * 1024 * 1024, 'JSON files must not exceed 2 MiB.');
        try {
            $data = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->check(false, 'Invalid JSON in '.basename($path));
        }
        $this->check(is_array($data), 'Expected JSON object or array: '.basename($path));

        return $data;
    }

    public function check(bool $valid, string $message): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['package' => $message]);
        }
    }

    public function strings(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count(array_filter($value,'is_string')) === count($value);
    }
}
