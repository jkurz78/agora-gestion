<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Exceptions\Compta\CompteNonLettrableException;
use App\Exceptions\Compta\LettrageDejaPresentException;
use App\Exceptions\Compta\LettrageMultiComptesException;
use App\Exceptions\Compta\LettrageNonEquilibreException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service de lettrage des écritures comptables (spec §5 de la spec slice 1).
 *
 * Phase D — Step 12 de plans/fondations-partie-double-slice1.md.
 *
 * Le lettrage est le mécanisme d'appariement de lignes débit/crédit sur un
 * même compte dont la somme algébrique est zéro. Il est append-only et audité
 * dans `lettrage_audit`.
 */
final class LettrageService
{
    /**
     * Lettre un ensemble de lignes sous un code commun.
     *
     * Vérifie les 5 invariants (compte unique, lettrable, équilibre,
     * pas-de-relettrage, tenant) avant toute écriture.
     *
     * @param  Collection<int, TransactionLigne>  $lignes  Toutes sur le même compte, même tenant.
     *                                                     Accepte Illuminate\Support\Collection ou Eloquent\Collection.
     * @param  string|null  $code  Si null, généré (UUID-short 20 chars).
     * @param  string|null  $motif  Optionnel, écrit en lettrage_audit.
     * @return string Le code de lettrage utilisé.
     *
     * @throws LettrageMultiComptesException
     * @throws CompteNonLettrableException
     * @throws LettrageNonEquilibreException
     * @throws LettrageDejaPresentException
     * @throws TenantBoundaryException
     */
    public function lettrer(Collection $lignes, ?string $code = null, ?string $motif = null): string
    {
        // Vérification des 5 invariants (toutes avant toute écriture)
        // L'ordre est important : tenant d'abord (sécurité), puis métier.
        $this->assertTenant($lignes);
        $this->assertSameCompte($lignes);
        $this->assertCompteLettrable($lignes);
        $this->assertEquilibre($lignes);
        $this->assertPasDeRelettrage($lignes);

        $code ??= Str::random(20);
        $ids = $lignes->pluck('id')->all();
        $compteId = (int) $lignes->first()->compte_id;

        DB::transaction(function () use ($ids, $compteId, $code, $motif): void {
            // 1. Insérer ligne audit (append-only)
            DB::table('lettrage_audit')->insert([
                'association_id' => TenantContext::currentId(),
                'action' => 'lettre',
                'lettrage_code' => $code,
                'compte_id' => $compteId,
                'transaction_ligne_ids' => json_encode($ids),
                'user_id' => Auth::id(),
                'motif' => $motif,
                'created_at' => now(),
            ]);

            // 2. Appliquer le code sur les lignes (atomique)
            TransactionLigne::whereIn('id', $ids)->update(['lettrage_code' => $code]);
        });

        return $code;
    }

    // -------------------------------------------------------------------------
    // Invariants privés (REFACTOR — Step 12)
    // -------------------------------------------------------------------------

    /**
     * Vérifie que toutes les lignes partagent le même compte_id.
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     *
     * @throws LettrageMultiComptesException
     */
    private function assertSameCompte(Collection $lignes): void
    {
        $compteIds = $lignes->pluck('compte_id')->unique();

        if ($compteIds->count() > 1) {
            throw LettrageMultiComptesException::detected();
        }
    }

    /**
     * Vérifie que le compte des lignes est lettrable.
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     *
     * @throws CompteNonLettrableException
     */
    private function assertCompteLettrable(Collection $lignes): void
    {
        $compteId = (int) $lignes->first()->compte_id;

        $compte = DB::table('comptes')->where('id', $compteId)->first(['id', 'numero_pcg', 'lettrable']);

        if ($compte === null || ! (bool) $compte->lettrable) {
            throw CompteNonLettrableException::forCompte(
                $compteId,
                $compte?->numero_pcg ?? 'inconnu'
            );
        }
    }

    /**
     * Vérifie que ∑ (debit - credit) = 0 sur les lignes.
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     *
     * @throws LettrageNonEquilibreException
     */
    private function assertEquilibre(Collection $lignes): void
    {
        $solde = $lignes->reduce(
            fn (string $carry, TransactionLigne $l): string => bcadd(
                $carry,
                bcsub((string) $l->debit, (string) $l->credit, 2),
                2
            ),
            '0.00'
        );

        if (bccomp($solde, '0.00', 2) !== 0) {
            throw LettrageNonEquilibreException::withSolde($solde);
        }
    }

    /**
     * Vérifie qu'aucune ligne ne porte déjà un lettrage_code.
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     *
     * @throws LettrageDejaPresentException
     */
    private function assertPasDeRelettrage(Collection $lignes): void
    {
        foreach ($lignes as $ligne) {
            if ($ligne->lettrage_code !== null) {
                throw LettrageDejaPresentException::forLigne($ligne->id, $ligne->lettrage_code);
            }
        }
    }

    /**
     * Vérifie que toutes les lignes appartiennent au tenant courant.
     *
     * Résolution via le compte de la ligne (bypass TenantScope intentionnel
     * pour permettre la détection cross-tenant).
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     *
     * @throws TenantBoundaryException
     */
    private function assertTenant(Collection $lignes): void
    {
        $currentTenantId = TenantContext::currentId();

        foreach ($lignes as $ligne) {
            $compteAssociationId = DB::table('comptes')
                ->where('id', $ligne->compte_id)
                ->value('association_id');

            if ((int) $compteAssociationId !== (int) $currentTenantId) {
                throw TenantBoundaryException::crossTenantLigne(
                    $ligne->id,
                    (int) $compteAssociationId,
                    (int) $currentTenantId
                );
            }
        }
    }
}
