<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;

class EdboRun extends Model
{
    protected $table = 'edbo_runs';

    protected $fillable = [
        'uuid',
        'task_uuid',
        'engine',
        'status',
        'config',
        'result_path',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'config' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
