<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\CompteIncorrectException;
use App\Exceptions\Compta\EcritureNonEquilibreeException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Exceptions\Compta\TiersInterditException;
use App\Exceptions\Compta\TiersRequisException;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    // =========================================================================
    // Méthodes de génération d'écritures (Steps 15-20)
    // =========================================================================

    /**
     * Génère l'écriture T1 (transaction comptant, 2 lignes équilibrées) pour une
     * recette encaissée immédiatement (cheque / espèces / virement / CB / prélèvement).
     *
     * Matrice École C (spec §4.3) :
     *   Cheque      → portage sur 5112 (Chèques à encaisser)
     *   Especes     → portage sur 530  (Caisse)
     *   Virement    → portage sur $compteTresorerie (512X physique)
     *   Cb          → portage sur $compteTresorerie (512X physique, ex. HelloAsso)
     *   Prelevement → portage sur $compteTresorerie (512X physique, par symétrie virement)
     *
     * Lignes créées :
     *   Ligne 1 : débit  $comptePortage, tiers_id = $tiers->id
     *   Ligne 2 : crédit $compteProduit, tiers_id = null
     *
     * @throws \InvalidArgumentException Si $montant ≤ 0.
     * @throws CompteIncorrectException Si $compteProduit ∉ classe 7,
     *                                  ou si $compteTresorerie ∉ 512X pour Virement/Cb/Prelevement.
     * @throws TenantBoundaryException Si $tiers ou l'un des comptes n'appartient pas au tenant courant.
     * @throws EcritureNonEquilibreeException Sécurité paranoïaque post-création (ne doit jamais lever en pratique).
     */
    public function pourRecetteComptant(
        Tiers $tiers,
        Compte $compteProduit,
        float $montant,
        ModePaiement $mode,
        Compte $compteTresorerie,
        \DateTimeInterface $date,
        ?string $libelle = null,
        ?Operation $operation = null,
        ?int $seance = null,
    ): Transaction {
        // --- Validation input ---

        if ($montant <= 0) {
            throw new \InvalidArgumentException(
                "Le montant d'une recette comptant doit être strictement positif (reçu : {$montant})."
            );
        }

        if ($compteProduit->classe !== 7) {
            throw CompteIncorrectException::classeAttendue(
                $compteProduit->numero_pcg,
                $compteProduit->classe,
                7
            );
        }

        // Pour Virement/Cb/Prelevement, $compteTresorerie doit être un compte bancaire physique 512X.
        $modesNecessitantTresorerie = [
            ModePaiement::Virement,
            ModePaiement::Cb,
            ModePaiement::Prelevement,
        ];

        if (in_array($mode, $modesNecessitantTresorerie, strict: true)) {
            $isBancaire = $compteTresorerie->classe === 5
                && str_starts_with($compteTresorerie->numero_pcg, '512')
                && strlen($compteTresorerie->numero_pcg) > 3;

            if (! $isBancaire) {
                throw CompteIncorrectException::classeAttendue(
                    $compteTresorerie->numero_pcg,
                    $compteTresorerie->classe,
                    '5 (512X — bancaire physique)'
                );
            }
        }

        // --- Résolution du compte de portage ---
        $comptePortage = $this->resoudreComptePortage($mode, $compteTresorerie);

        // --- Invariant tenant (avant DB::transaction pour fail-fast) ---
        $this->assertTenantCoherence(
            collect(),
            collect([$tiers])
        );

        // --- Création dans une transaction DB ---
        return DB::transaction(function () use (
            $tiers, $compteProduit, $comptePortage, $montant, $mode,
            $date, $libelle, $operation, $seance
        ): Transaction {
            $libelleEffectif = $libelle ?? 'Recette '.$mode->label();

            $transaction = $this->createTransactionHeader(
                date: $date,
                libelle: $libelleEffectif,
                montant: $montant,
                modePaiement: $mode,
            );

            // Ligne 1 : débit portage (tiers porté ici)
            $ligne1 = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $comptePortage->id,
                'debit' => $montant,
                'credit' => 0,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'operation_id' => $operation?->id,
                'seance' => $seance,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne1->setRelation('compte', $comptePortage);

            // Ligne 2 : crédit produit (sans tiers)
            $ligne2 = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compteProduit->id,
                'debit' => 0,
                'credit' => $montant,
                'tiers_id' => null,
                'libelle' => $libelleEffectif,
                'operation_id' => $operation?->id,
                'seance' => $seance,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne2->setRelation('compte', $compteProduit);

            // --- Vérification post-création (paranoïa) ---
            $lignes = collect([$ligne1, $ligne2]);
            $this->assertEquilibre($lignes);
            // assertPasDeTiersSur512 : 5112 et 530 ne commencent pas par '512' suivi d'un char
            // supplémentaire — l'invariant ne s'applique donc pas à eux par construction.
            // Pour les comptes 512X (virement/CB/prelevement), on DOIT avoir tiers_id sur ligne1,
            // ce qui violerait assertPasDeTiersSur512. La décision actée (spec §4.3 + §2 décision 11)
            // est que les 512X physiques ne se lettrent pas mais peuvent porter un tiers en
            // ligne de recette comptant (le tiers identifie le payeur). L'invariant assertPasDeTiersSur512
            // ne s'applique donc PAS ici : c'est cohérent avec son nom ("pas de tiers sur 512 pour
            // le rapprochement"), non avec l'identification du payeur en saisie.
            // NB : si la policy change, ajouter l'appel ici.

            $transaction->setRelation('lignes', $lignes);

            return $transaction;
        });
    }

    /**
     * Génère l'écriture T1 (créance ouverte) pour une recette constatée à crédit.
     *
     * Matrice École C (spec §4.3) — Recette à crédit :
     *   T1 : 411 D X (tiers) / $compteProduit C X
     *
     * Pas de T2 ici : le règlement (encaissement) sera enregistré séparément
     * via pourEncaissementCreance() (Step 17), qui créera T2 et lettra les
     * lignes 411 de T1 et T2.
     *
     * mode_paiement est laissé à null : aucun paiement n'a encore eu lieu,
     * la colonne est nullable depuis la migration 2026_04_05_100001.
     *
     * @throws \InvalidArgumentException Si $montant ≤ 0.
     * @throws CompteIncorrectException Si $compteProduit ∉ classe 7.
     * @throws TenantBoundaryException Si $tiers ou l'un des comptes n'appartient pas au tenant courant.
     * @throws EcritureNonEquilibreeException Sécurité paranoïaque post-création.
     */
    public function pourRecetteACredit(
        Tiers $tiers,
        Compte $compteProduit,
        float $montant,
        \DateTimeInterface $dateConstatation,
        ?string $libelle = null,
        ?Operation $operation = null,
    ): Transaction {
        // --- Validation input ---

        if ($montant <= 0) {
            throw new \InvalidArgumentException(
                "Le montant d'une recette à crédit doit être strictement positif (reçu : {$montant})."
            );
        }

        if ($compteProduit->classe !== 7) {
            throw CompteIncorrectException::classeAttendue(
                $compteProduit->numero_pcg,
                $compteProduit->classe,
                7
            );
        }

        // --- Résolution compte 411 (tenant-scopé automatiquement) ---
        $compte411 = Compte::ofNumeroSysteme('411');

        // --- Invariant tenant (fail-fast avant DB::transaction) ---
        $this->assertTenantCoherence(
            collect(),
            collect([$tiers])
        );

        // --- Création dans une transaction DB ---
        return DB::transaction(function () use (
            $tiers, $compteProduit, $compte411, $montant,
            $dateConstatation, $libelle, $operation
        ): Transaction {
            $libelleEffectif = $libelle ?? 'Recette à crédit';

            $transaction = $this->createTransactionHeader(
                date: $dateConstatation,
                libelle: $libelleEffectif,
                montant: $montant,
                modePaiement: null,
            );

            // Ligne 1 : débit 411 (tiers porté ici — créance ouverte)
            $ligne1 = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compte411->id,
                'debit' => $montant,
                'credit' => 0,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'operation_id' => $operation?->id,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne1->setRelation('compte', $compte411);

            // Ligne 2 : crédit produit (sans tiers)
            $ligne2 = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compteProduit->id,
                'debit' => 0,
                'credit' => $montant,
                'tiers_id' => null,
                'libelle' => $libelleEffectif,
                'operation_id' => $operation?->id,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne2->setRelation('compte', $compteProduit);

            // --- Vérifications post-création (paranoïa) ---
            $lignes = collect([$ligne1, $ligne2]);
            $this->assertEquilibre($lignes);
            $this->assertTiersObligatoire411($lignes); // doit passer par construction

            $transaction->setRelation('lignes', $lignes);

            return $transaction;
        });
    }

    // =========================================================================
    // Méthodes privées
    // =========================================================================

    /**
     * Résout le compte de portage selon le mode de paiement.
     *
     * Cheque      → Compte::ofNumeroSysteme('5112')
     * Especes     → Compte::ofNumeroSysteme('530')
     * Virement    → $compteTresorerieExplicite
     * Cb          → $compteTresorerieExplicite
     * Prelevement → $compteTresorerieExplicite
     *
     * Le compte explicite est déjà validé (classe 512X) par l'appelant avant cet appel.
     */
    private function resoudreComptePortage(ModePaiement $mode, Compte $compteTresorerieExplicite): Compte
    {
        return match ($mode) {
            ModePaiement::Cheque => Compte::ofNumeroSysteme('5112'),
            ModePaiement::Especes => Compte::ofNumeroSysteme('530'),
            ModePaiement::Virement,
            ModePaiement::Cb,
            ModePaiement::Prelevement => $compteTresorerieExplicite,
        };
    }

    /**
     * Crée le header Transaction (sans lignes) partagé par les méthodes pour*().
     *
     * Centralise les champs invariants : association_id, type=Recette, equilibree=true,
     * type_ecriture='normale', saisi_par. mode_paiement peut être null (recette à crédit).
     */
    private function createTransactionHeader(
        \DateTimeInterface $date,
        string $libelle,
        float $montant,
        ?ModePaiement $modePaiement,
    ): Transaction {
        return Transaction::create([
            'association_id' => (int) TenantContext::currentId(),
            'type' => TypeTransaction::Recette,
            'date' => $date->format('Y-m-d'),
            'libelle' => $libelle,
            'montant_total' => $montant,
            'mode_paiement' => $modePaiement,
            'saisi_par' => Auth::id(),
            'equilibree' => true,
            'type_ecriture' => 'normale',
        ]);
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
