<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('participant_documents')->get()->each(function (stdClass $row): void {
            $old = $row->storage_path;

            // Si storage_path contient déjà juste le nom court (sans '/'), pas de migration fichier nécessaire
            // mais on vérifie quand même s'il faut déplacer un fichier depuis l'ancien emplacement
            $shortName = basename($old);

            if ($old === $shortName) {
                // Déjà au format court — pas de changement DB nécessaire
                return;
            }

            // Chemin sous l'ancien format : "participants/{pid}/{filename}"
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
                rename($fullOld, $fullNew);
            }

            DB::table('participant_documents')
                ->where('id', $row->id)
                ->update(['storage_path' => $shortName]);
        });
    }

    public function down(): void
    {
        // Non réversible sans backup — restore depuis sauvegarde si nécessaire.
    }
};
