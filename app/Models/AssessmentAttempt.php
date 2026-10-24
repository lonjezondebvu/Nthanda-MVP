<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['responses' => 'array', 'result' => 'array'];
    }
}
