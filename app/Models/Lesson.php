<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        static::creating(function (Lesson $lesson): void {
            $lesson->workspace = static::currentWorkspace();
        });
    }

    protected function casts(): array
    {
        return ['report' => 'array'];
    }
}
