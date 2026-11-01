<?php

namespace App\Models;

use Database\Factories\GlossaryTermFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlossaryTerm extends Model
{
    /** @use HasFactory<GlossaryTermFactory> */
    use HasFactory;

    protected $fillable = ['lesson_id', 'term', 'explanation_en', 'explanation_ny', 'pronunciation_hint', 'reviewer_id', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @param Builder<GlossaryTerm> $query
     * @return Builder<GlossaryTerm>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('lesson', fn (Builder $lesson): Builder => $lesson->visibleTo($user))
            ->when(! in_array($user->role, ['teacher', 'admin'], true), fn (Builder $terms): Builder => $terms->whereNotNull('reviewed_at'));
    }
}
