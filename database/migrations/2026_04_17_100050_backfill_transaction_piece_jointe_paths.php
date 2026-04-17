<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->whereNotNull('piece_jointe_path')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $old = $row->piece_jointe_path;

                    // Déjà migré : valeur courte sans slash (ex: "justificatif.pdf")
                    if (! str_contains($old, '/')) {
                        continue;
                    }

                    $new = basename($old); // "doc.pdf"

                    $fullOld = storage_path('app/private/'.$old);
                    $fullNew = storage_path(
                        'app/private/associations/'.$row->association_id
                        .'/transactions/'.$row->id.'/'.$new
                    );

                    if (is_file($fullOld)) {
                        @mkdir(dirname($fullNew), 0775, true);
                        @rename($fullOld, $fullNew);
                    }

                    DB::table('transactions')
                        ->where('id', $row->id)
                        ->update(['piece_jointe_path' => $new]);
                }
            });
    }

    public function down(): void
    {
        // Retour arrière non implémenté : restaurer manuellement depuis sauvegarde si nécessaire.
    }
};
