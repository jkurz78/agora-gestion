<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DC-10a du programme « dissolution sous_categories → comptes ».
 *
 * `helloasso_parametres.sous_categorie_don_id` (FK sous_categories, hors de la
 * liste des 11 FKs migrées en DC-2 — oubli d'inventaire) reçoit son pendant
 * compte-first : `compte_don_id` (FK comptes, nullable). Backfill in-migration
 * via le mapping `sous_categories.code_cerfa = comptes.numero_pcg` (même
 * association) — boucle Eloquent-less compatible SQLite (tests).
 *
 * `sous_categorie_don_id` n'est pas retiré : drop en DC-10b avec la table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table): void {
            $table->foreignId('compte_don_id')->nullable()->after('sous_categorie_don_id')
                ->constrained('comptes')->nullOnDelete();
        });

        // Backfill : pour chaque paramétrage portant une sous-catégorie don,
        // résoudre le compte miroir (code_cerfa = numero_pcg, même association).
        $rows = DB::table('helloasso_parametres')
            ->whereNotNull('sous_categorie_don_id')
            ->get(['id', 'association_id', 'sous_categorie_don_id']);

        foreach ($rows as $row) {
            $codeCerfa = DB::table('sous_categories')
                ->where('id', $row->sous_categorie_don_id)
                ->value('code_cerfa');

            if ($codeCerfa === null) {
                continue;
            }

            $compteId = DB::table('comptes')
                ->where('association_id', $row->association_id)
                ->where('numero_pcg', $codeCerfa)
                ->whereNull('deleted_at')
                ->value('id');

            if ($compteId !== null) {
                DB::table('helloasso_parametres')
                    ->where('id', $row->id)
                    ->update(['compte_don_id' => $compteId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('compte_don_id');
        });
    }
};
