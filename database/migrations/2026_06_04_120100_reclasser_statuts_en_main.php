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
 * No-op sous tenant sans schéma PD, et no-op si compta.use_partie_double=false
 * (le syncer gate là-dessus) → à exécuter après activation du flag (cutover).
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
