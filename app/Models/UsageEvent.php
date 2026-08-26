<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'kind', 'quantity', 'meta', 'occurred_at', 'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'quantity' => 'integer',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
