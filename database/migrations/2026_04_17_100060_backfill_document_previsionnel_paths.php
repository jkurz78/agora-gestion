<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('documents_previsionnels')
            ->whereNotNull('pdf_path')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $old = $row->pdf_path;

                    // Déjà migré : valeur courte sans slash (ex: "42.pdf")
                    if (! str_contains($old, '/')) {
                        continue;
                    }

                    // Nom court : juste le basename
                    $new = basename($old);

                    // Chemin physique ancien : storage/app/private/documents-previsionnels/{name}
                    $fullOld = storage_path('app/private/'.$old);
                    // Chemin physique cible : storage/app/private/associations/{aid}/documents-previsionnels/{name}
                    $fullNew = storage_path(
                        'app/private/associations/'.$row->association_id
                        .'/documents-previsionnels/'.$new
                    );

                    if (is_file($fullOld)) {
                        @mkdir(dirname($fullNew), 0775, true);
                        @rename($fullOld, $fullNew);
                    }

                    DB::table('documents_previsionnels')
                        ->where('id', $row->id)
                        ->update(['pdf_path' => $new]);
                }
            });
    }

    public function down(): void
    {
        // Retour arrière non implémenté : restaurer manuellement depuis sauvegarde si nécessaire.
    }
};
