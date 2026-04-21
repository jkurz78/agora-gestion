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
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->boolean('saisie_automatisee')
                ->default(false)
                ->after('actif_recettes_depenses');
        });

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
        $legacyIds = DB::table('comptes_bancaires')
            ->whereIn('nom', ['Créances à recevoir', 'Remises en banque'])
            ->pluck('id')
            ->all();

        if (! empty($legacyIds)) {
            $blockers = [
                'transactions' => DB::table('transactions')->whereIn('compte_id', $legacyIds)->count(),
                'remises_bancaires' => DB::table('remises_bancaires')->whereIn('compte_cible_id', $legacyIds)->count(),
                'rapprochements_bancaires' => DB::table('rapprochements_bancaires')->whereIn('compte_id', $legacyIds)->count(),
                'virements_source' => DB::table('virements_internes')->whereIn('compte_source_id', $legacyIds)->count(),
                'virements_destination' => DB::table('virements_internes')->whereIn('compte_destination_id', $legacyIds)->count(),
                'helloasso_compte' => DB::table('helloasso_parametres')->whereIn('compte_helloasso_id', $legacyIds)->count(),
                'helloasso_versement' => DB::table('helloasso_parametres')->whereIn('compte_versement_id', $legacyIds)->count(),
                'factures' => DB::table('factures')->whereIn('compte_bancaire_id', $legacyIds)->count(),
                'associations_facture_compte' => DB::table('association')->whereIn('facture_compte_bancaire_id', $legacyIds)->count(),
            ];

            $offenders = array_filter($blockers, fn ($n) => $n > 0);
            if (! empty($offenders)) {
                throw new \RuntimeException(
                    'Migration refonte_comptes_bancaires_saisie abortée : FK résiduelles sur comptes legacy — '
                    .json_encode($offenders, JSON_UNESCAPED_UNICODE)
                );
            }

            DB::table('comptes_bancaires')->whereIn('id', $legacyIds)->delete();
        }

        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->dropColumn('est_systeme');
        });
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
