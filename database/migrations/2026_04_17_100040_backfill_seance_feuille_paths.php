<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('seances')
            ->whereNotNull('feuille_signee_path')
            ->get()
            ->each(function (object $row): void {
                $old = $row->feuille_signee_path;

                if (! str_contains($old, '/')) {
                    return; // déjà migré
                }

                $associationId = $row->association_id;
                $seanceId = $row->id;

                $fullOld = storage_path('app/private/'.$old);
                $fullNew = storage_path('app/private/associations/'.$associationId.'/seances/'.$seanceId.'/feuille-signee.pdf');

                if (is_file($fullOld) && ! is_file($fullNew)) {
                    @mkdir(dirname($fullNew), 0775, true);
                    @rename($fullOld, $fullNew);
                }

                if (is_file($fullNew)) {
                    DB::table('seances')
                        ->where('id', $seanceId)
                        ->update(['feuille_signee_path' => 'feuille-signee.pdf']);
                } else {
                    Log::warning('S2 backfill: expected file missing, DB not updated', [
                        'table' => 'seances', 'id' => $seanceId,
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
