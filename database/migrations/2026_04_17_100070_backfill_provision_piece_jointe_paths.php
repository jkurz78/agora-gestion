<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('provisions')
            ->whereNotNull('piece_jointe_path')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $old = $row->piece_jointe_path;

                    // Déjà migré : valeur courte sans slash (ex: "piece-jointe.pdf")
                    if (! str_contains($old, '/')) {
                        continue;
                    }

                    // Nom court : juste le basename
                    $new = basename($old);

                    // Chemin physique ancien : storage/app/private/provisions/{uuid}/{filename}
                    $fullOld = storage_path('app/private/'.$old);
                    // Chemin physique cible : storage/app/private/associations/{aid}/provisions/{pid}/{filename}
                    $fullNew = storage_path(
                        'app/private/associations/'.$row->association_id
                        .'/provisions/'.$row->id
                        .'/'.$new
                    );

                    if (is_file($fullOld)) {
                        @mkdir(dirname($fullNew), 0775, true);
                        @rename($fullOld, $fullNew);
                    }

                    DB::table('provisions')
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
