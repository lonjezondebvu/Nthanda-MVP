<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LessonPublicationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['lesson_id', 'reviewer_id', 'action', 'note', 'fingerprint', 'snapshot', 'created_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Publication audit events cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Publication audit events cannot be deleted.'));
    }
}
