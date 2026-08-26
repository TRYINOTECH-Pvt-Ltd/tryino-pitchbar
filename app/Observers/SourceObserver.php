<?php

namespace App\Observers;

use App\Listeners\AutoPublishOnFirstIndex;
use App\Models\Source;

class SourceObserver
{
    public function __construct(private AutoPublishOnFirstIndex $autoPublish) {}

    public function updated(Source $source): void
    {
        // Only react when status transitions TO "indexed" (not on every save).
        if ($source->wasChanged('status') && $source->status === 'indexed') {
            $this->autoPublish->handle($source);
        }
    }
}
