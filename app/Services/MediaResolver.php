<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class MediaResolver
{
    public const MIMES = ['webp' => 'image/webp', 'webm' => 'video/webm', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'mp4' => 'video/mp4', 'html' => 'text/html', 'vtt' => 'text/vtt', 'txt' => 'text/plain'];

    public function resolve(string $source, string $derived, array $manifest, ManifestParser $parser, ?callable $progress = null): array
    {
        $assets = [];
        $media = [];
        $warnings = [];
        $missing = [];
        $groups = [];
        foreach ($manifest['media'] as $index => $item) {
            if ($progress !== null) {
                $progress(['stage' => 'media', 'percent' => 78 + (int) (15 * ($index + 1) / count($manifest['media'])), 'message' => $item['path'], 'current' => $index + 1, 'total' => count($manifest['media'])]);
            }
            $path = 'dist/'.$parser->safePath($item['path']);
            if (preg_match('/^(.*\.mp4)\.part(\d+)$/', $path, $matches)) {
                $groups[$matches[1]][(int) $matches[2]] = ['path' => $path, 'item' => $item];

                continue;
            }
            if (! is_file($source.'/'.$path)) {
                $parser->check(! ($item['required'] ?? false), 'Missing required media: '.$item['path']);
                $missing[] = $item['path'];
                $warnings[] = 'Optional media unavailable: '.$item['id'];

                continue;
            }
            $item['asset_key'] = $this->asset($assets, $source, $path);
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'webm' && in_array($item['type'], ['audio', 'pronunciation'], true)) {
                $assets[$item['asset_key']]['mime'] = 'audio/webm';
            }
            foreach (['captions_path', 'transcript_path'] as $key) {
                if (! empty($item['accessibility'][$key])) {
                    $ref = 'dist/'.$parser->safePath($item['accessibility'][$key]);
                    $parser->check(is_file($source.'/'.$ref), 'Missing accessibility file: '.$ref);
                    $item[$key === 'captions_path' ? 'captions_key' : 'transcript_key'] = $this->asset($assets, $source, $ref);
                }
            }
            $media[] = $item;
        }
        foreach ($groups as $base => $parts) {
            ksort($parts);
            $parser->check(array_keys($parts) === range(0, count($parts) - 1), 'Video chunk sequence is incomplete.');
            // This pilot has exactly four binary segments, not four independent video clips.
            if ($manifest['lesson_id'] === 'JCE-BIO-U10' && $manifest['lesson_version'] === '2.1.0-pilot') {
                $parser->check(count($parts) === 4, 'Expected four legacy video chunks.');
            }
            $file = 'video-'.hash('sha256', $base).'.mp4';
            $target = $derived.'/'.$file;
            $out = fopen($target, 'wb');
            $size = 0;
            try {
                foreach ($parts as $part) {
                    if ($progress !== null) {
                        $progress(['stage' => 'media', 'percent' => 93, 'message' => 'Joining video segment: '.$part['path']]);
                    }
                    $parser->check(is_file($source.'/'.$part['path']), 'Missing video segment.');
                    $in = fopen($source.'/'.$part['path'], 'rb');
                    $size += stream_copy_to_stream($in, $out);
                    fclose($in);
                }
            } finally {
                fclose($out);
            }
            if ($progress !== null) {
                $progress(['stage' => 'media', 'percent' => 94, 'message' => 'Validating reconstructed video: '.$base]);
            }
            $this->validateMp4($target, $size, $parser);
            $item = reset($parts)['item'];
            $item['chunk_ids'] = array_column(array_column($parts, 'item'), 'id');
            $item['asset_key'] = hash('sha256', 'derived/'.$file);
            $assets[$item['asset_key']] = ['path' => 'derived/'.$file, 'mime' => 'video/mp4', 'bytes' => $size, 'sha256' => hash_file('sha256', $target)];
            foreach (['captions_path' => 'captions_key', 'transcript_path' => 'transcript_key'] as $pathKey => $outputKey) {
                if (! empty($item['accessibility'][$pathKey])) {
                    $path = 'dist/'.$parser->safePath($item['accessibility'][$pathKey]);
                    $parser->check(is_file($source.'/'.$path), 'Missing video accessibility asset.');
                    $item[$outputKey] = $this->asset($assets, $source, $path);
                }
            }
            $media[] = $item;
            $warnings[] = 'Joined '.count($parts).' binary video parts in numeric suffix order; validated MP4 container (ftyp, moov, mdat). Caption timing still needs listening review.';
        }

        return compact('assets', 'media', 'warnings', 'missing');
    }

    private function asset(array &$assets, string $root, string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! isset(self::MIMES[$ext])) {
            throw ValidationException::withMessages(['package' => 'Unsupported learner asset type.']);
        }
        $key = hash('sha256', $path);
        $assets[$key] = ['path' => 'source/'.$path, 'mime' => self::MIMES[$ext], 'bytes' => filesize($root.'/'.$path)];

        return $key;
    }

    private function validateMp4(string $path, int $size, ManifestParser $parser): void
    {
        $in = fopen($path, 'rb');
        $boxes = [];
        $offset = 0;
        try {
            while ($offset < $size) {
                $header = fread($in, 8);
                $parser->check(strlen($header) === 8, 'Truncated MP4 header.');
                $box = unpack('Nsize/a4type', $header);
                $length = $box['size'];
                $headerSize = 8;
                if ($length === 1) {
                    $wide = unpack('Nhigh/Nlow', fread($in, 8));
                    $length = $wide['high'] * 4294967296 + $wide['low'];
                    $headerSize = 16;
                }
                if ($length === 0) {
                    $length = $size - $offset;
                }
                $parser->check($length >= $headerSize && $offset + $length <= $size, 'Invalid MP4 box length.');
                $boxes[] = $box['type'];
                $offset += $length;
                fseek($in, $offset);
            }
        } finally {
            fclose($in);
        }
        foreach (['ftyp', 'moov', 'mdat'] as $box) {
            $parser->check(in_array($box, $boxes), 'Incomplete MP4 container.');
        }
    }
}
