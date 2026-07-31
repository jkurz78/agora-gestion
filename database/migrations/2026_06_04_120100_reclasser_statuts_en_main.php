<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Transaction;
use App\Services\Compta\EtatReglementResolver;
use App\Tenant\TenantContext;
use Illuminate\Database\Migrations\Migration;

/**
 * Chantier 4 — reclasse les statuts via le resolver (one-shot, rejouable).
 *
 * En pratique : seules les recettes chèque/espèces reçues mais non remises
 * (colonne 'recu', portage 5112/530 non lettré) basculent vers 'en_main'.
 * Le resolver est idempotent : les autres tx restent inchangées.
 *
 * Itère par association (TenantContext requis pour le scope global).
 *
 * ⚠️ Cette migration ne suffit pas au cutover. Elle s'exécute au `migrate`,
 * donc avant `compta:backfill-partie-double` : les transactions legacy n'ont pas
 * encore de ligne partie double, le resolver retombe sur la colonne stockée
 * (EtatReglementResolver::resolve) et la reclassification est un no-op sur cette
 * population. La reclassification réelle se fait avec
 * `php artisan compta:reconcilier-statuts` APRÈS le backfill — voir la séquence
 * de bascule dans docs/compta-partie-double.md §8.
 *
 * (Le syncer ne dépend d'aucune option : la partie double est inconditionnelle.
 * Voir docs/adr/004-partie-double-inconditionnelle.md.)
 */
return new class extends Migration
{
    public function up(): void
    {
        $resolver = app(EtatReglementResolver::class);

        Association::query()->each(function (Association $association) use ($resolver): void {
            TenantContext::boot($association);

            Transaction::query()->each(function (Transaction $tx) use ($resolver): void {
                $resolver->syncer($tx);
            });

            TenantContext::clear();
        });
    }

    public function down(): void
    {
        // Irréversible côté données (le statut reste dérivable). No-op.
    }
};
