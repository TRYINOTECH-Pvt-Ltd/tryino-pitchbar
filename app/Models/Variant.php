<?php

namespace App\Models;

use App\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Variant extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $fillable = ['experiment_id', 'name', 'config', 'weight'];

    protected $casts = [
        'config' => 'array',
        'weight' => 'integer',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }
}
