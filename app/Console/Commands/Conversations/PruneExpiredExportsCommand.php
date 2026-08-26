<?php

namespace App\Console\Commands\Conversations;

use App\Models\ConversationExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneExpiredExportsCommand extends Command
{
    protected $signature = 'conversations:prune-exports';

    protected $description = 'Delete conversation_exports rows + files whose expires_at has passed.';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $deleted = 0;

        ConversationExport::query()
            ->withoutWorkspaceScope()
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($exports) use ($disk, &$deleted): void {
                foreach ($exports as $export) {
                    if (! empty($export->file_path) && $disk->exists($export->file_path)) {
                        $disk->delete($export->file_path);
                    }
                    $export->delete();
                    $deleted++;
                }
            });

        $this->info("Pruned {$deleted} expired conversation exports.");

        return self::SUCCESS;
    }
}
