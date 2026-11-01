<?php

namespace App\Models;

use Database\Factories\PortfolioEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioEntry extends Model
{
    /** @use HasFactory<PortfolioEntryFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @param  Builder<PortfolioEntry>  $query
     * @return Builder<PortfolioEntry>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->whereHas('learner', fn (Builder $learner) => $learner->where('role', 'learner')->whereColumn('users.school_class_id', 'portfolio_entries.school_class_id'))
            ->whereHas('lesson', fn (Builder $lesson) => $lesson->visibleTo($user)->whereHas('unit.subject', fn (Builder $subject) => $subject->whereColumn('subjects.school_class_id', 'portfolio_entries.school_class_id')));

        return match ($user->role) {
            'learner' => $query->where('user_id', $user->id)->where('school_class_id', $user->school_class_id),
            'teacher' => $query->whereIn('school_class_id', $user->taughtClasses()->select('school_classes.id')),
            'admin' => $query,
            default => $query->whereRaw('1 = 0'),
        };
    }
}
