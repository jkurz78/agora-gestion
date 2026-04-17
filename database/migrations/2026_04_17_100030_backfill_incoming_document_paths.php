<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('incoming_documents')->get()->each(function (object $row): void {
            $old = $row->storage_path;

            if (! str_contains($old, '/')) {
                return; // déjà migré
            }

            $shortName = basename($old);
            $associationId = $row->association_id;

            $fullOld = storage_path('app/private/'.$old);
            $fullNew = storage_path('app/private/associations/'.$associationId.'/incoming-documents/'.$shortName);

            if (is_file($fullOld) && ! is_file($fullNew)) {
                @mkdir(dirname($fullNew), 0775, true);
                @rename($fullOld, $fullNew);
            }

            if (is_file($fullNew)) {
                DB::table('incoming_documents')
                    ->where('id', $row->id)
                    ->update(['storage_path' => $shortName]);
            } else {
                Log::warning('S2 backfill: expected file missing, DB not updated', [
                    'table' => 'incoming_documents', 'id' => $row->id,
                    'old_value' => $old, 'expected_new_path' => $fullNew,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Non réversible sans backup — restaurer depuis sauvegarde si nécessaire.
    }
};
