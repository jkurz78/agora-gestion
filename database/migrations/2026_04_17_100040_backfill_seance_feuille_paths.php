<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('seances')
            ->whereNotNull('feuille_signee_path')
            ->get()
            ->each(function (object $row): void {
                $old = $row->feuille_signee_path;

                // Déjà migré : valeur courte sans slash
                if (! str_contains($old, '/')) {
                    return;
                }

                $associationId = $row->association_id;
                $seanceId = $row->id;

                $fullOld = storage_path('app/private/'.$old);
                $fullNew = storage_path('app/private/associations/'.$associationId.'/seances/'.$seanceId.'/feuille-signee.pdf');

                if (is_file($fullOld)) {
                    @mkdir(dirname($fullNew), 0775, true);
                    rename($fullOld, $fullNew);
                }

                DB::table('seances')
                    ->where('id', $seanceId)
                    ->update(['feuille_signee_path' => 'feuille-signee.pdf']);
            });
    }

    public function down(): void
    {
        // Retour arrière non implémenté : restaurer manuellement depuis sauvegarde si nécessaire.
    }
};
