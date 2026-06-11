<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Patch prod juin 2026 — deux correctifs indépendants dans une même commande.
 *
 * FIX 1 — Don HelloAsso 200 € manquant (rapprochement #3, 30/09/2025)
 *   Transaction créée manuellement lors de la reprise de données pour pointer
 *   un don HelloAsso antérieur au logiciel. Hard-deletée accidentellement
 *   (probablement lors d'un re-sync HelloAsso). Sans elle le rapprochement
 *   bancaire #3 affiche un écart de 200 €.
 *   → Recréer la transaction (recette 200 €, Don HelloAsso, tiers #7
 *     DONNATEURS VIA HELLOASSO, compte courant #1) et la pointer dans
 *     le rapprochement #3.
 *
 * FIX 2 — Doublons lignes HelloAsso (TX #90, #91, #113)
 *   Le sync initial (mars 2025) a créé des lignes sans helloasso_item_id.
 *   Un re-sync ultérieur avec upsertLigne (clé d'idempotence sur
 *   helloasso_item_id) a recréé les mêmes lignes → montant doublé
 *   (25 € × 2 = 50 € au lieu de 25 €). Impacte les remises bancaires
 *   #6 (100 → 50 €) et #10 (180 → 155 €).
 *   → Supprimer les lignes doublons (sans helloasso_item_id), recalculer
 *     montant_total des TX, corriger montant_facial des adhésions liées,
 *     recalculer montant_total des T4 remises.
 */
final class FixProdJuin2026Command extends Command
{
    protected $signature = 'fix:prod-juin-2026 {--dry-run : Afficher les corrections sans les appliquer}';

    protected $description = 'Patch prod juin 2026 : don 200 € manquant + doublons lignes HelloAsso';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('MODE DRY-RUN — aucune modification ne sera appliquée.');
        }

        $association = Association::first();
        if ($association === null) {
            $this->error('Aucune association trouvée.');

            return self::FAILURE;
        }
        TenantContext::boot($association);

        $this->newLine();
        $ok1 = $this->fixDonManquant($dryRun);
        $this->newLine();
        $ok2 = $this->fixDoublonsHelloAsso($dryRun);

        $this->newLine();
        if ($dryRun) {
            $this->warn('Relancer sans --dry-run pour appliquer les deux correctifs.');
        } else {
            $this->info('✅ Patch terminé — les deux correctifs ont été appliqués.');
        }

        return ($ok1 && $ok2) ? self::SUCCESS : self::FAILURE;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FIX 1 — Don 200 € manquant
    // ═══════════════════════════════════════════════════════════════════

    private function fixDonManquant(bool $dryRun): bool
    {
        $this->info('── FIX 1 : Don HelloAsso 200 € manquant (rappro #3) ──');

        // Gardes : vérifier que le rappro #3 a bien un écart de 200 €
        $rappro = DB::table('rapprochements_bancaires')->where('id', 3)->first();
        if ($rappro === null) {
            $this->error('  Rapprochement #3 introuvable.');

            return false;
        }

        $soldeOuverture = (float) $rappro->solde_ouverture;
        $soldeFin = (float) $rappro->solde_fin;

        $netPointe = (float) DB::table('transactions')
            ->where('rapprochement_id', 3)
            ->selectRaw("SUM(CASE WHEN type = 'depense' THEN -montant_total ELSE montant_total END) as total")
            ->value('total');

        $soldePointage = round($soldeOuverture + $netPointe, 2);
        $ecart = round($soldeFin - $soldePointage, 2);

        $this->line("  Solde ouverture : {$soldeOuverture}");
        $this->line("  Solde pointage  : {$soldePointage}");
        $this->line("  Solde fin       : {$soldeFin}");
        $this->line("  Écart actuel    : {$ecart}");

        if ((int) round($ecart * 100) === 0) {
            $this->info('  Écart déjà nul — rien à corriger.');

            return true;
        }

        if ((int) round($ecart * 100) !== 20000) {
            $this->error("  Écart inattendu ({$ecart} € au lieu de 200 €). Correctif non appliqué.");

            return false;
        }

        // Vérifier que le tiers #7 existe
        $tiers = DB::table('tiers')->where('id', 7)->first();
        if ($tiers === null) {
            $this->error('  Tiers #7 (DONNATEURS VIA HELLOASSO) introuvable.');

            return false;
        }

        if ($dryRun) {
            $this->warn('  → Créer TX recette 200 €, date 2025-08-31, tiers #7, compte #1, pointée rappro #3');

            return true;
        }

        DB::transaction(function (): void {
            $now = now();
            $txId = DB::table('transactions')->insertGetId([
                'association_id' => TenantContext::currentId(),
                'type' => 'recette',
                'date' => '2025-08-31',
                'libelle' => 'Don HelloAsso (reprise)',
                'montant_total' => 200.00,
                'mode_paiement' => 'virement',
                'compte_id' => 1,
                'tiers_id' => 7,
                'rapprochement_id' => 3,
                'statut_reglement' => 'pointe',
                'saisi_par' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('transaction_lignes')->insert([
                'transaction_id' => $txId,
                'sous_categorie_id' => 1,
                'montant' => 200.00,
            ]);

            $this->info("  TX#{$txId} créée, pointée dans rappro #3.");
        });

        // Vérification post-fix
        $netApres = (float) DB::table('transactions')
            ->where('rapprochement_id', 3)
            ->selectRaw("SUM(CASE WHEN type = 'depense' THEN -montant_total ELSE montant_total END) as total")
            ->value('total');
        $ecartApres = round($soldeFin - ($soldeOuverture + $netApres), 2);
        $this->info("  Écart après correction : {$ecartApres}");

        return (int) round($ecartApres * 100) === 0;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FIX 2 — Doublons lignes HelloAsso
    // ═══════════════════════════════════════════════════════════════════

    private function fixDoublonsHelloAsso(bool $dryRun): bool
    {
        $this->info('── FIX 2 : Doublons lignes HelloAsso (TX #90, #91, #113) ──');

        // Identifier les doublons
        $txIds = DB::table('transactions')
            ->whereNotNull('helloasso_payment_id')
            ->pluck('id');

        $doublons = [];

        foreach ($txIds as $txId) {
            $lignes = DB::table('transaction_lignes')
                ->where('transaction_id', $txId)
                ->whereNotNull('sous_categorie_id')
                ->whereNull('deleted_at')
                ->get(['id', 'sous_categorie_id', 'helloasso_item_id', 'montant']);

            $grouped = $lignes->groupBy('sous_categorie_id');

            foreach ($grouped as $scId => $group) {
                $withHA = $group->filter(fn ($l) => $l->helloasso_item_id !== null);
                $withoutHA = $group->filter(fn ($l) => $l->helloasso_item_id === null);

                if ($withHA->count() > 0 && $withoutHA->count() > 0) {
                    foreach ($withoutHA as $doublon) {
                        $doublons[] = [
                            'transaction_id' => (int) $txId,
                            'ligne_id' => (int) $doublon->id,
                            'montant' => (float) $doublon->montant,
                            'sous_categorie_id' => (int) $scId,
                        ];
                    }
                }
            }
        }

        if (empty($doublons)) {
            $this->info('  Aucun doublon détecté. Rien à corriger.');

            return true;
        }

        $this->line('  '.count($doublons).' doublon(s) détecté(s) :');
        foreach ($doublons as $d) {
            $this->line("    TX#{$d['transaction_id']} — L#{$d['ligne_id']} ({$d['montant']}€, sc={$d['sous_categorie_id']})");
        }

        $txImpactees = collect($doublons)->pluck('transaction_id')->unique();

        DB::transaction(function () use ($doublons, $txImpactees, $dryRun): void {
            // 2a. Supprimer les lignes doublons
            $ligneIds = collect($doublons)->pluck('ligne_id')->all();

            if ($dryRun) {
                $this->warn('  → Supprimer lignes : '.implode(', ', array_map(fn ($id) => "L#{$id}", $ligneIds)));
            } else {
                DB::table('transaction_lignes')->whereIn('id', $ligneIds)->delete();
                $this->info('  '.count($ligneIds).' ligne(s) doublon supprimée(s).');
            }

            // 2b. Recalculer montant_total des TX impactées
            foreach ($txImpactees as $txId) {
                $oldTotal = (float) DB::table('transactions')->where('id', $txId)->value('montant_total');

                if ($dryRun) {
                    // En dry-run, calculer le nouveau total en excluant les doublons
                    $doublonIds = collect($doublons)
                        ->where('transaction_id', $txId)
                        ->pluck('ligne_id')
                        ->all();
                    $newTotal = (float) DB::table('transaction_lignes')
                        ->where('transaction_id', $txId)
                        ->whereNotNull('sous_categorie_id')
                        ->whereNull('deleted_at')
                        ->whereNotIn('id', $doublonIds)
                        ->sum('montant');
                    $this->warn("  → TX#{$txId} : montant_total {$oldTotal}€ → {$newTotal}€");
                } else {
                    $newTotal = (float) DB::table('transaction_lignes')
                        ->where('transaction_id', $txId)
                        ->whereNotNull('sous_categorie_id')
                        ->whereNull('deleted_at')
                        ->sum('montant');

                    DB::table('transactions')->where('id', $txId)->update([
                        'montant_total' => $newTotal,
                        'updated_at' => now(),
                    ]);
                    $this->info("  TX#{$txId} : montant_total {$oldTotal}€ → {$newTotal}€");
                }
            }

            // 2c. Corriger montant_facial des adhésions liées
            $adhesions = DB::table('adhesions')
                ->whereIn('transaction_id', $txImpactees->all())
                ->whereNull('deleted_at')
                ->get(['id', 'transaction_id', 'montant_facial']);

            foreach ($adhesions as $adh) {
                $oldMontant = (float) $adh->montant_facial;

                if ($dryRun) {
                    $doublonIds = collect($doublons)
                        ->where('transaction_id', $adh->transaction_id)
                        ->pluck('ligne_id')
                        ->all();
                    $newMontant = (float) DB::table('transaction_lignes')
                        ->where('transaction_id', $adh->transaction_id)
                        ->whereNotNull('sous_categorie_id')
                        ->whereNull('deleted_at')
                        ->whereNotIn('id', $doublonIds)
                        ->sum('montant');
                } else {
                    $newMontant = (float) DB::table('transaction_lignes')
                        ->where('transaction_id', $adh->transaction_id)
                        ->whereNotNull('sous_categorie_id')
                        ->whereNull('deleted_at')
                        ->sum('montant');
                }

                if ((int) round($oldMontant * 100) !== (int) round($newMontant * 100)) {
                    if ($dryRun) {
                        $this->warn("  → Adhésion #{$adh->id} : montant_facial {$oldMontant}€ → {$newMontant}€");
                    } else {
                        DB::table('adhesions')->where('id', $adh->id)->update([
                            'montant_facial' => $newMontant,
                            'updated_at' => now(),
                        ]);
                        $this->info("  Adhésion #{$adh->id} : montant_facial {$oldMontant}€ → {$newMontant}€");
                    }
                }
            }

            // 2d. Recalculer montant_total des T4 remises impactées
            $remiseIds = DB::table('transactions')
                ->whereIn('id', $txImpactees->all())
                ->whereNotNull('remise_id')
                ->pluck('remise_id')
                ->unique();

            foreach ($remiseIds as $remiseId) {
                $sourcesTotal = (float) DB::table('transactions')
                    ->where('remise_id', $remiseId)
                    ->where('type', 'recette')
                    ->whereNull('deleted_at')
                    ->whereNotIn('id', function ($q) use ($remiseId) {
                        $q->select('id')
                            ->from('transactions')
                            ->where('remise_id', $remiseId)
                            ->where('libelle', 'like', 'Remise%');
                    })
                    ->sum('montant_total');

                $t4 = DB::table('transactions')
                    ->where('remise_id', $remiseId)
                    ->where('libelle', 'like', 'Remise%')
                    ->first(['id', 'montant_total']);

                if ($t4 !== null) {
                    $oldT4 = (float) $t4->montant_total;
                    if ((int) round($oldT4 * 100) !== (int) round($sourcesTotal * 100)) {
                        if ($dryRun) {
                            $this->warn("  → T4 remise #{$remiseId} (TX#{$t4->id}) : montant_total {$oldT4}€ → {$sourcesTotal}€");
                        } else {
                            DB::table('transactions')->where('id', $t4->id)->update([
                                'montant_total' => $sourcesTotal,
                                'updated_at' => now(),
                            ]);
                            $this->info("  T4 remise #{$remiseId} (TX#{$t4->id}) : montant_total {$oldT4}€ → {$sourcesTotal}€");
                        }
                    }
                }
            }
        });

        return true;
    }
}
