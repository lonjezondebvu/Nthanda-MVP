<?php

namespace App\Services;

use App\Models\Lesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class PackageImporter
{
    public function __construct(private ManifestParser $parser, private LegacyPackageAdapter $adapter, private MediaResolver $resolver) {}

    public function import(string $zipPath, ?callable $progress = null): array
    {
        $emit = $progress ?? static function (array $event): void {};
        $emit(['stage' => 'validate', 'percent' => 0, 'message' => 'Checking archive size and existing versions']);
        $this->parser->check(is_file($zipPath) && filesize($zipPath) <= 128 * 1024 * 1024, 'ZIP must be at most 128 MiB.');
        $sha = hash_file('sha256', $zipPath);
        if ($existing = Lesson::where('sha256', $sha)->first()) {
            return ['lesson' => $existing, 'duplicate' => true];
        }
        $directory = 'imports/'.Str::uuid();
        $disk = Storage::disk('local');
        $root = $disk->path($directory);
        $disk->makeDirectory($directory.'/source');
        $disk->makeDirectory($directory.'/derived');
        try {
            copy($zipPath, $root.'/original.zip');
            $this->extract($zipPath, $root.'/source', $emit);
            $emit(['stage' => 'manifest', 'percent' => 40, 'message' => 'Reading lesson-manifest.json and validating curriculum references']);
            $manifest = $this->parser->parse($root.'/source');
            $this->parser->check(! Lesson::where('manifest_id', $manifest['lesson_id'])->where('version', $manifest['lesson_version'])->exists(), 'This lesson version already exists with different bytes. Increment the package version before importing.');
            $source = $root.'/source';
            $sections = [];
            foreach ($manifest['sections'] as $s) {
                $content = $this->parser->json($source.'/dist/'.$s['content_path']);
                $sections[] = [...$content, 'order' => $s['order'], 'title' => $s['title'], 'criterion_ids' => $s['criterion_ids']];
                $emit(['stage' => 'sections', 'percent' => 45 + (int) (25 * count($sections) / count($manifest['sections'])), 'message' => $s['title'], 'current' => count($sections), 'total' => count($manifest['sections'])]);
            }
            $emit(['stage' => 'assessments', 'percent' => 72, 'message' => 'Validating assessment questions, marking rules and written work']);
            $marking = $this->parser->json($source.'/'.$this->parser->safePath($manifest['assessments']['protected_marking_reference']));
            $this->parser->json($source.'/'.$this->parser->safePath($manifest['assessments']['protected_source_rubric_reference']));
            $written = $this->parser->json($source.'/dist/'.$this->parser->safePath($manifest['assessments']['learner_written_path']));
            $this->parser->check($this->parser->strings($written['text'] ?? null), 'Written assessment text must be an array of strings.');
            $this->validateAssessment($marking, $manifest);
            $compat = $this->adapter->adapt($source, $manifest, $this->parser);
            $resolvedManifest = $manifest;
            $resolvedManifest['media'] = [...$manifest['media'], ...$compat['additional_media']];
            $emit(['stage' => 'media', 'percent' => 78, 'message' => 'Resolving images, recordings, captions and video segments']);
            $media = $this->resolver->resolve($source, $root.'/derived', $resolvedManifest, $this->parser, $emit);
            $assets = $media['assets'];
            $logo = 'dist/media/branding/nthanda-logo.png';
            if (is_file($source.'/'.$logo)) {
                $assets[hash('sha256', $logo)] = ['path' => 'source/'.$logo, 'mime' => 'image/png', 'bytes' => filesize($source.'/'.$logo)];
            }
            $assembled = ['manifest' => $manifest, 'sections' => $sections, 'media' => $media['media'], 'assets' => $assets, 'compatibility' => $compat, 'written' => $written];
            $disk->put($directory.'/derived/lesson.json', json_encode($assembled, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $report = ['sections' => count($sections), 'blocks' => array_sum(array_map(fn ($s) => count($s['blocks']), $sections)),
                'media' => count($media['media']), 'assets' => count($assets), 'questions' => count($marking['questions']), 'activities' => count($compat['activities']),
                'warnings' => array_merge($media['warnings'], ['Legacy JSON assignment parsed as data; formative activities extracted into derived metadata.', 'Reading, confidence and exploration are stored separately from assessed achievement.'], $manifest['publication']['blockers'] ?? []),
                'missing_files' => $media['missing'], 'unsupported_features' => ['Production publication remains blocked.', 'Legacy workbook library and optional tutorial videos are outside this prototype.'],
                'sha256' => $sha, 'adapter' => $compat['adapter'], 'publication' => $manifest['publication']];
            $emit(['stage' => 'save', 'percent' => 95, 'message' => 'Saving curriculum and import report']);
            $lesson = DB::transaction(function () use ($manifest, $sha, $directory, $report, $source): Lesson {
                $lesson = Lesson::create(['manifest_id' => $manifest['lesson_id'], 'version' => $manifest['lesson_version'], 'title' => $manifest['title'] ?? $manifest['lesson_id'], 'sha256' => $sha, 'directory' => $directory, 'report' => $report]);
                app(CurriculumPackages::class)->restoreSettings($lesson, $source);

                return $lesson;
            });

            return ['lesson' => $lesson, 'duplicate' => false];
        } catch (\Throwable $e) {
            $disk->deleteDirectory($directory);
            throw $e;
        }
    }

    private function extract(string $file, string $target, callable $progress): void
    {
        $zip = new ZipArchive;
        $this->parser->check($zip->open($file) === true, 'Cannot open ZIP archive.');
        $allowed = ['json', 'js', 'html', 'css', 'png', 'jpg', 'jpeg', 'svg', 'gif', 'webp', 'webm', 'wav', 'ogg', 'mp3', 'm4a', 'mp4', 'vtt', 'srt', 'txt', 'md', 'pdf', 'docx', 'csv', 'py', 'pyc', 'bat'];
        try {
            $this->parser->check($zip->numFiles <= 1500, 'Archive has too many entries.');
            $total = 0;
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $s = $zip->statIndex($i);
                $name = $s['name'];
                $path = rtrim($name, '/');
                $this->parser->safePath($path);
                $this->parser->check(! isset($seen[strtolower($path)]), 'Duplicate archive path.');
                $seen[strtolower($path)] = true;
                $opsys = 0;
                $attr = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                $this->parser->check((($attr >> 16) & 0170000) !== 0120000, 'Archive symlinks are not permitted.');
                $this->parser->check(($s['encryption_method'] ?? 0) === 0, 'Encrypted entries are not supported.');
                $total += $s['size'];
                $this->parser->check($s['size'] <= 64 * 1024 * 1024 && $total <= 256 * 1024 * 1024, 'Archive extraction limit exceeded.');
                $this->parser->check($s['size'] < 1024 * 1024 || $s['size'] / max(1, $s['comp_size']) <= 250, 'Suspicious archive compression ratio.');
                if (str_ends_with($name, '/')) {
                    if (! is_dir($target.'/'.$path)) {
                        mkdir($target.'/'.$path, 0775, true);
                    }

                    continue;
                }
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $this->parser->check(in_array($ext, $allowed) || preg_match('/\.mp4\.part\d+$/D', $path), 'Unsupported archive file type: '.$ext);
                $this->parser->check(! str_contains($path, '//'), 'Empty path components are not allowed.');
                $dest = $target.'/'.$path;
                if (! is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0775, true);
                }
                $in = $zip->getStream($name);
                $this->parser->check(is_resource($in), 'Cannot read archive member.');
                $out = fopen($dest, 'wb');
                $bytes = stream_copy_to_stream($in, $out, $s['size'] + 1);
                fclose($in);
                fclose($out);
                $this->parser->check($bytes === $s['size'], 'Archive member size mismatch.');
                $this->parser->check(strtolower(hash_file('crc32b', $dest)) === sprintf('%08x', $s['crc']), 'Archive member checksum failed.');
                $progress(['stage' => 'extract', 'percent' => (int) (40 * ($i + 1) / $zip->numFiles), 'message' => $name, 'current' => $i + 1, 'total' => $zip->numFiles]);
            }
        } finally {
            $zip->close();
        }
    }

    private function validateAssessment(array $marking, array $manifest): void
    {
        $ids = [];
        $sections = array_column($manifest['sections'], 'id');
        foreach ($marking['questions'] ?? [] as $q) {
            $this->parser->check(isset($q['id'],$q['criterionId'],$q['kind'],$q['answer'],$q['maxMarks']) && ! isset($ids[$q['id']]), 'Invalid or duplicate assessment question.');
            $ids[$q['id']] = $q;
            $this->parser->check(is_string($q['prompt'] ?? null) && is_string($q['criterionId']) && is_string($q['domain'] ?? null) && is_int($q['maxMarks']) && $q['maxMarks'] > 0, 'Malformed assessment question fields.');
            $this->parser->check(in_array($q['kind'], ['mc', 'multi', 'short', 'match', 'order']), 'Unsupported assessment question kind.');
            if (in_array($q['kind'], ['mc', 'multi'])) {
                $this->parser->check($this->parser->strings($q['options'] ?? null), 'Assessment choices must be an array of strings.');
            }
            if ($q['kind'] === 'mc') {
                $this->parser->check(is_string($q['answer']), 'Multiple choice key must be a string.');
            }
            if (in_array($q['kind'], ['short', 'multi', 'order'])) {
                $this->parser->check($this->parser->strings($q['answer']) && count($q['answer']) > 0, 'Structured answer key must contain strings.');
            }
            if ($q['kind'] === 'match') {
                $this->parser->check(is_array($q['answer']) && ! array_is_list($q['answer']) && count(array_filter($q['answer'], 'is_string')) === count($q['answer']), 'Matching key must contain string pairs.');
            }
            foreach ($q['sectionIds'] ?? [] as $id) {
                $this->parser->check(in_array($id, $sections), 'Unknown question section.');
            }
        }
        $this->parser->check((($manifest['publication']['status'] ?? null) === 'draft' && count($ids) === 0 && count($marking['rules'] ?? []) === 0) || (count($ids) > 0 && count($marking['rules'] ?? []) > 0), 'Assessment questions and rules are required.');
        foreach ($marking['rules'] as $rule) {
            $max = 0;
            foreach ($rule['required_question_ids'] as $id) {
                $this->parser->check(isset($ids[$id]), 'Missing required assessment question.');
                $this->parser->check($ids[$id]['criterionId'] === $rule['criterion_id'], 'Assessment criterion mismatch.');
                $max += $ids[$id]['maxMarks'];
            }
            $this->parser->check($max === $rule['max_marks'] && $rule['minimum_overall_ratio'] > 0 && $rule['minimum_overall_ratio'] <= 1, 'Invalid marking threshold.');
        }
        foreach ($manifest['assessment_links'] as $link) {
            $this->parser->check(isset($ids[$link['assessment_id']]), 'Unknown assessment link.');
        }
    }
}
