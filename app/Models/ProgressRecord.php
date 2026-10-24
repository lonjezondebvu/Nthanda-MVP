<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgressRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['read' => 'boolean', 'confidence' => 'integer', 'activities' => 'array'];
    }
}
