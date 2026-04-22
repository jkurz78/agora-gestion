<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refonte du concept "compte système" :
 *  - Ajoute saisie_automatisee (intégration externe, ex. HelloAsso)
 *  - Backfill compte HelloAsso à saisie_automatisee=true
 *  - Supprime les 2 comptes legacy (Créances à recevoir, Remises en banque)
 *  - Drop colonne est_systeme devenue obsolète
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent : si la colonne existe déjà (tentative précédente partielle),
        // on reprend à l'étape suivante.
        if (! Schema::hasColumn('comptes_bancaires', 'saisie_automatisee')) {
            Schema::table('comptes_bancaires', function (Blueprint $table) {
                $table->boolean('saisie_automatisee')
                    ->default(false)
                    ->after('actif_recettes_depenses');
            });
        }

        $helloassoCompteIds = DB::table('helloasso_parametres')
            ->whereNotNull('compte_helloasso_id')
            ->pluck('compte_helloasso_id')
            ->all();

        if (! empty($helloassoCompteIds)) {
            DB::table('comptes_bancaires')
                ->whereIn('id', $helloassoCompteIds)
                ->update(['saisie_automatisee' => true]);
        }

        // Lookup by name: the prior migration (2026_04_19_130013) already set
        // est_systeme=false on these accounts, so we cannot filter on est_systeme=true.
        // Idempotent : si les comptes legacy ont déjà été supprimés, le result est vide et
        // toutes les étapes suivantes sont no-op.
        $legacyIds = DB::table('comptes_bancaires')
            ->whereIn('nom', ['Créances à recevoir', 'Remises en banque'])
            ->pluck('id')
            ->all();

        if (! empty($legacyIds)) {
            // Hard-delete des lignes déjà soft-deleted pointant vers les comptes legacy.
            // Elles sont déjà considérées supprimées par l'app — on les purge physiquement
            // pour libérer les FK RESTRICT (virements_internes notamment).
            DB::table('transactions')
                ->whereIn('compte_id', $legacyIds)
                ->whereNotNull('deleted_at')
                ->delete();
            DB::table('virements_internes')
                ->where(function ($q) use ($legacyIds) {
                    $q->whereIn('compte_source_id', $legacyIds)
                        ->orWhereIn('compte_destination_id', $legacyIds);
                })
                ->whereNotNull('deleted_at')
                ->delete();
            DB::table('remises_bancaires')
                ->whereIn('compte_cible_id', $legacyIds)
                ->whereNotNull('deleted_at')
                ->delete();

            // FK guard : ne compte QUE les lignes actives (non soft-deleted).
            $blockers = [
                'transactions' => DB::table('transactions')->whereIn('compte_id', $legacyIds)->whereNull('deleted_at')->count(),
                'remises_bancaires' => DB::table('remises_bancaires')->whereIn('compte_cible_id', $legacyIds)->whereNull('deleted_at')->count(),
                'rapprochements_bancaires' => DB::table('rapprochements_bancaires')->whereIn('compte_id', $legacyIds)->count(),
                'virements_source' => DB::table('virements_internes')->whereIn('compte_source_id', $legacyIds)->whereNull('deleted_at')->count(),
                'virements_destination' => DB::table('virements_internes')->whereIn('compte_destination_id', $legacyIds)->whereNull('deleted_at')->count(),
                'helloasso_compte' => DB::table('helloasso_parametres')->whereIn('compte_helloasso_id', $legacyIds)->count(),
                'helloasso_versement' => DB::table('helloasso_parametres')->whereIn('compte_versement_id', $legacyIds)->count(),
                'factures' => DB::table('factures')->whereIn('compte_bancaire_id', $legacyIds)->count(),
                'associations_facture_compte' => DB::table('association')->whereIn('facture_compte_bancaire_id', $legacyIds)->count(),
            ];

            $offenders = array_filter($blockers, fn ($n) => $n > 0);
            if (! empty($offenders)) {
                throw new RuntimeException(
                    'Migration refonte_comptes_bancaires_saisie abortée : FK résiduelles sur comptes legacy — '
                    .json_encode($offenders, JSON_UNESCAPED_UNICODE)
                );
            }

            // DELETE des comptes legacy. La FK transactions.compte_id est nullOnDelete —
            // les soft-deleted éventuels restants auront compte_id NULL automatiquement.
            DB::table('comptes_bancaires')->whereIn('id', $legacyIds)->delete();
        }

        // Idempotent : si la colonne a déjà été droppée (tentative précédente partielle
        // qui aurait passé ce bloc), on skippe.
        if (Schema::hasColumn('comptes_bancaires', 'est_systeme')) {
            Schema::table('comptes_bancaires', function (Blueprint $table) {
                $table->dropColumn('est_systeme');
            });
        }
    }

    public function down(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->boolean('est_systeme')->default(false)->after('actif_recettes_depenses');
        });
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->dropColumn('saisie_automatisee');
        });
    }
};
