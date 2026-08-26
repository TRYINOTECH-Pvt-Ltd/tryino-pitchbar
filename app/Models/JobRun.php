<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $job_class
 * @property string $queue
 * @property ?string $agent_id
 * @property string $status
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?int $duration_ms
 * @property ?string $exception_first_line
 * @property int $attempt
 */
class JobRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'job_class',
        'queue',
        'agent_id',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'exception_first_line',
        'attempt',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'attempt' => 'integer',
        'duration_ms' => 'integer',
    ];
}
