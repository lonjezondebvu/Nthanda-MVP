<?php

namespace App\Services;

use App\Models\ContentSetting;
use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PublicationReview
{
    public function fingerprint(Lesson $lesson): string
    {
        $disk = Storage::disk('local');
        $sourceText = $disk->get($lesson->directory.'/derived/lesson.json');
        $source = json_decode($sourceText, true, 128, JSON_THROW_ON_ERROR);
        $markingPath = $lesson->directory.'/source/'.$source['manifest']['assessments']['protected_marking_reference'];
        $settings = ContentSetting::query()->where('lesson_id', $lesson->id)->orderBy('kind')->orderBy('item_id')->get(['kind', 'item_id', 'enabled', 'deleted', 'position'])->toArray();

        return hash('sha256', json_encode([
            'source' => hash('sha256', $sourceText),
            'marking' => hash('sha256', $disk->get($markingPath)),
            'metadata' => [$lesson->manifest_id, $lesson->version, $lesson->title, $lesson->sha256, $lesson->workspace, $lesson->unit_id, $lesson->report],
            'settings' => $settings,
        ], JSON_THROW_ON_ERROR));
    }

    public function isCurrent(Lesson $lesson): bool
    {
        $review = $lesson->publication_review;
        if ($lesson->published_at === null || ! is_array($review) || ! is_string($review['fingerprint'] ?? null)) {
            return false;
        }
        try {
            return hash_equals($review['fingerprint'], $this->fingerprint($lesson));
        } catch (Throwable) {
            return false;
        }
    }
}
