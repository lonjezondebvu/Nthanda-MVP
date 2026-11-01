<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentSetting extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $invalidate = function (ContentSetting $setting): void {
            Lesson::withoutGlobalScopes()->whereKey($setting->lesson_id)->update(['published_at' => null]);
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'deleted' => 'boolean'];
    }
}
