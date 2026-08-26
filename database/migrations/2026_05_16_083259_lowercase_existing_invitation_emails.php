<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('invitations')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $lower = mb_strtolower((string) ($row->email ?? ''));
                if ($lower !== '' && $lower !== $row->email) {
                    DB::table('invitations')->where('id', $row->id)->update(['email' => $lower]);
                }
            }
        });
    }

    public function down(): void
    {
        // No-op. Original case is unrecoverable.
    }
};
