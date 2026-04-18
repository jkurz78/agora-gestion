<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('participant_documents')->get()->each(function (stdClass $row): void {
            $old = $row->storage_path;
            $shortName = basename($old);

            if ($old === $shortName) {
                return; // déjà au format court
            }

            $associationId = $row->association_id ?? DB::table('participants')
                ->where('id', $row->participant_id)
                ->value('association_id');

            if (! $associationId) {
                return;
            }

            $fullOld = storage_path('app/private/'.$old);
            $fullNew = storage_path('app/private/associations/'.$associationId.'/participants/'.$row->participant_id.'/'.$shortName);

            if (is_file($fullOld) && ! is_file($fullNew)) {
                if (! is_dir(dirname($fullNew))) {
                    @mkdir(dirname($fullNew), 0775, true);
                }
                @rename($fullOld, $fullNew);
            }

            if (is_file($fullNew)) {
                DB::table('participant_documents')
                    ->where('id', $row->id)
                    ->update(['storage_path' => $shortName]);
            } else {
                Log::warning('S2 backfill: expected file missing, DB not updated', [
                    'table' => 'participant_documents', 'id' => $row->id,
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
