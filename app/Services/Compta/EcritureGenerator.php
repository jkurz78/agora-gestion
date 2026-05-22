<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Exceptions\Compta\EcritureNonEquilibreeException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Exceptions\Compta\TiersInterditException;
use App\Exceptions\Compta\TiersRequisException;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Service central de génération des écritures partie double (spec §4).
 *
 * Step 14 : squelette + invariants partagés publics.
 * Steps 15-20 : méthodes pour*() à ajouter.
 *
 * Décision de visibilité (Step 14) : les méthodes d'invariants sont exposées
 * en PUBLIC pour permettre les tests unitaires directs avant que les méthodes
 * pour*() existent. Une fois Steps 15-20 livrés, on réévaluera si réduire
 * la visibilité à protected/private est souhaitable (ou si on garde public
 * pour la testabilité et la réutilisabilité depuis d'éventuels services dérivés).
 *
 * La méthode generateLettrageCode() reste publique : elle est potentiellement
 * utile à d'autres services (ex : orchestrateurs de remise qui veulent pré-générer
 * un code avant de créer les lignes).
 *
 * Factorisation vs LettrageService : LettrageService utilise Str::random(20)
 * directement — pas de méthode publique statique exposée. EcritureGenerator
 * dispose de sa propre méthode generateLettrageCode() qui délègue à Str::random(20).
 * Pas de duplication de logique métier : les deux usages sont identiques
 * (20 chars aléatoires), et le couplage fort entre les deux services pour factoriser
 * 1 ligne serait sur-ingénierie. Si on veut unifier, on extraira un Trait ou une
 * constante dans une classe utilitaire dédiée (Step REFACTOR post-20).
 */
final class EcritureGenerator
{
    public function __construct(private readonly LettrageService $lettrageService) {}

    // =========================================================================
    // Invariants partagés (publics en Step 14 — voir note de visibilité ci-dessus)
    // =========================================================================

    /**
     * Vérifie que ∑ débits = ∑ crédits sur la collection de lignes.
     *
     * Utilise bcmath (précision 2 décimales) pour éviter les erreurs de
     * virgule flottante sur de gros montants.
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     *
     * @throws EcritureNonEquilibreeException
     */
    public function assertEquilibre(Collection $lignes): void
    {
        $totalDebit = $lignes->reduce(
            fn (string $carry, TransactionLigne $l): string => bcadd($carry, (string) $l->debit, 2),
            '0.00'
        );

        $totalCredit = $lignes->reduce(
            fn (string $carry, TransactionLigne $l): string => bcadd($carry, (string) $l->credit, 2),
            '0.00'
        );

        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw EcritureNonEquilibreeException::withSolde($totalDebit, $totalCredit);
        }
    }

    /**
     * Vérifie que tous les comptes (via la relation compte chargée sur chaque
     * ligne) et tous les tiers passés en argument appartiennent au tenant
     * courant.
     *
     * La relation compte doit être chargée sur les lignes avant l'appel
     * (eager-load ou setRelation dans les tests). Si le compte n'est pas
     * chargé, la vérification est ignorée pour cette ligne (défensif :
     * les méthodes pour*() garantissent le chargement en contexte réel).
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     * @param  Collection<int, Tiers>|null  $tiers  Tiers supplémentaires à vérifier (optionnel).
     *
     * @throws TenantBoundaryException
     */
    public function assertTenantCoherence(Collection $lignes, ?Collection $tiers = null): void
    {
        $currentTenantId = (int) TenantContext::currentId();

        foreach ($lignes as $ligne) {
            $compte = $ligne->relationLoaded('compte') ? $ligne->compte : null;

            if ($compte !== null && (int) $compte->association_id !== $currentTenantId) {
                throw TenantBoundaryException::crossTenantLigne(
                    (int) $ligne->id,
                    (int) $compte->association_id,
                    $currentTenantId
                );
            }
        }

        if ($tiers !== null) {
            foreach ($tiers as $t) {
                if ((int) $t->association_id !== $currentTenantId) {
                    throw TenantBoundaryException::crossTenantTiers(
                        (int) $t->id,
                        (int) $t->association_id,
                        $currentTenantId
                    );
                }
            }
        }
    }

    /**
     * Vérifie que chaque ligne dont le compte a numero_pcg === '411' ou '401'
     * porte un tiers_id non null.
     *
     * La relation compte doit être chargée sur les lignes avant l'appel.
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     *
     * @throws TiersRequisException
     */
    public function assertTiersObligatoire411(Collection $lignes): void
    {
        $comptesAvecTiersObligatoire = ['411', '401'];

        foreach ($lignes as $ligne) {
            $compte = $ligne->relationLoaded('compte') ? $ligne->compte : null;

            if ($compte === null) {
                continue;
            }

            if (
                in_array($compte->numero_pcg, $comptesAvecTiersObligatoire, strict: true)
                && $ligne->tiers_id === null
            ) {
                throw TiersRequisException::surCompte($compte->numero_pcg);
            }
        }
    }

    /**
     * Vérifie qu'aucune ligne dont le compte a numero_pcg commençant par '512'
     * (comptes bancaires physiques 5121, 5122, etc.) ne porte un tiers_id.
     *
     * Note : 5112 (Chèques à encaisser) commence par '511', pas '512' — il est
     * donc exclu de cet invariant et peut porter un tiers_id (comportement voulu).
     * Le scope bancaires() filtre '512_%' (un caractère obligatoire après '512').
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     *
     * @throws TiersInterditException
     */
    public function assertPasDeTiersSur512(Collection $lignes): void
    {
        foreach ($lignes as $ligne) {
            $compte = $ligne->relationLoaded('compte') ? $ligne->compte : null;

            if ($compte === null) {
                continue;
            }

            // '512_' = 512 suivi d'au moins 1 caractère — cohérent avec scopeBancaires()
            if (
                str_starts_with($compte->numero_pcg, '512')
                && strlen($compte->numero_pcg) > 3
                && $ligne->tiers_id !== null
            ) {
                throw TiersInterditException::surCompte512($compte->numero_pcg);
            }
        }
    }

    /**
     * Génère un code de lettrage unique (20 caractères aléatoires).
     *
     * Format identique à celui utilisé par LettrageService::lettrer() quand
     * aucun code n'est fourni. Les codes sont cryptographiquement robustes
     * (via Str::random → random_bytes en interne) et statistiquement uniques.
     */
    public function generateLettrageCode(): string
    {
        return Str::random(20);
    }
}
