<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('incoming_documents')->get()->each(function (object $row): void {
            $old = $row->storage_path;

            // Déjà migré (pas de slash = nom court seulement)
            if (! str_contains($old, '/')) {
                return;
            }

            $shortName = basename($old);
            $associationId = $row->association_id;

            $fullOld = storage_path('app/private/'.$old);
            $fullNew = storage_path('app/private/associations/'.$associationId.'/incoming-documents/'.$shortName);

            if (is_file($fullOld)) {
                @mkdir(dirname($fullNew), 0775, true);
                rename($fullOld, $fullNew);
            }

            DB::table('incoming_documents')
                ->where('id', $row->id)
                ->update(['storage_path' => $shortName]);
        });
    }

    public function down(): void
    {
        // Retour arrière non implémenté : restaurer manuellement depuis sauvegarde si nécessaire.
    }
};
