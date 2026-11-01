<?php

namespace App\Models;

use App\Services\PublicationReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    protected $guarded = [];

    public static function currentWorkspace(): string
    {
        return request()->hasSession() ? request()->session()->get('studio_workspace', 'main') : 'main';
    }

    protected static function booted(): void
    {
        static::addGlobalScope('workspace', fn (Builder $query) => $query->where('workspace', static::currentWorkspace()));
        static::updating(function (Lesson $lesson): void {
            if ($lesson->isDirty(['title', 'unit_id', 'directory', 'sha256', 'version', 'manifest_id', 'workspace', 'report'])) {
                $lesson->published_at = null;
            }
        });
        static::creating(function (Lesson $lesson): void {
            $lesson->workspace = static::currentWorkspace();
        });
    }

    protected function casts(): array
    {
        return ['report' => 'array', 'publication_review' => 'array', 'published_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function visibleTo(User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        $this->loadMissing('unit.subject');
        $classId = $this->unit?->subject?->school_class_id;
        if ($user->role === 'teacher') {
            return $classId !== null && $user->taughtClasses()->whereKey($classId)->exists();
        }

        return $user->role === 'learner' && $user->school_class_id !== null && $classId === $user->school_class_id
            && (config('learning.allow_preview') || app(PublicationReview::class)->isCurrent($this));
    }

    /**
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }
        if ($user->role === 'teacher') {
            return $query->whereHas('unit.subject', fn (Builder $subject) => $subject->whereIn('school_class_id', $user->taughtClasses()->select('school_classes.id')));
        }
        if ($user->role !== 'learner') {
            return $query->whereKey([]);
        }
        if ($user->school_class_id === null) {
            return $query->whereKey([]);
        }

        return $query->whereHas('unit.subject', fn (Builder $subject) => $subject->where('school_class_id', $user->school_class_id))
            ->when(! config('learning.allow_preview'), fn (Builder $lessons) => $lessons->whereNotNull('published_at'));
    }
}
