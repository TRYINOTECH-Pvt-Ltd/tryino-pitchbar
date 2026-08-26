<?php

namespace App\Models;

use App\Http\Controllers\Internal\QueueTickController;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per Cloudflare-worker → Laravel tick. Used by the admin's
 * "Cron worker health" panel to prove the Worker is alive and
 * actually doing work. Trimmed to the most recent 200 rows on every
 * insert by {@see QueueTickController}.
 */
class CronTickLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'received_at',
        'processed',
        'failed_in_tick',
        'remaining_pending',
        'failed_total',
        'elapsed_ms',
        'source',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'processed' => 'integer',
        'failed_in_tick' => 'integer',
        'remaining_pending' => 'integer',
        'failed_total' => 'integer',
        'elapsed_ms' => 'integer',
    ];
}
