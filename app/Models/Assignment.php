<?php

namespace App\Models;

use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory;

    protected $fillable = ['title', 'instructions', 'due_at', 'archived_at', 'school_class_id', 'lesson_id', 'teacher_id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /** @param Builder<Assignment> $query
     * @return Builder<Assignment>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->whereHas('lesson', fn (Builder $lesson) => $lesson->visibleTo($user)->whereHas('unit.subject', fn (Builder $subject) => $subject->whereColumn('subjects.school_class_id', 'assignments.school_class_id')));
        if ($user->role === 'learner') {
            return $query->where('school_class_id', $user->school_class_id)->whereNull('archived_at');
        }
        if ($user->role === 'teacher') {
            return $query->whereIn('school_class_id', $user->taughtClasses()->select('school_classes.id'));
        }

        return $user->role === 'admin' ? $query : $query->whereRaw('1 = 0');
    }
}
