<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\CompteIncorrectException;
use App\Exceptions\Compta\EcritureNonEquilibreeException;
use App\Exceptions\Compta\LettrageDejaPresentException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Exceptions\Compta\TiersInterditException;
use App\Exceptions\Compta\TiersRequisException;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\RemiseBancaire;
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
     * Vérifie qu'aucune ligne sur un compte de classe 5 (trésorerie : 512X, 5112, 530…)
     * ne porte de tiers_id. Spec §4.2 invariant 5 amendé 2026-05-22 : école 411
     * systématique, conformité FEC (CompAuxNum réservé classe 4).
     *
     * Le tiers vit exclusivement sur les comptes auxiliaires 411 et 401. La traçabilité
     * par tiers des mouvements de trésorerie passe par les lignes 411/401 contrepassées
     * dans la même transaction (recette/dépense comptant) ou par lettrage 411 ↔ 411
     * entre constatation et encaissement (recette à crédit).
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     *
     * @throws TiersInterditException
     */
    public function assertPasDeTiersSurClasse5(Collection $lignes): void
    {
        foreach ($lignes as $ligne) {
            $compte = $ligne->relationLoaded('compte') ? $ligne->compte : null;

            if ($compte === null) {
                continue;
            }

            if ($compte->classe === 5 && $ligne->tiers_id !== null) {
                throw TiersInterditException::surCompteClasse5($compte->numero_pcg);
            }
        }
    }

    // =========================================================================
    // Méthodes de génération d'écritures (Steps 15-20)
    // =========================================================================

    /**
     * Génère l'écriture T1 (recette comptant) à (N+3) lignes — école 411 systématique.
     *
     * Amendée 2026-05-22 : signature multi-ventilation, schéma N+3.
     * Matrice école 411 (spec §4.3 amendée) :
     *   411 D total tiers / [7x C × N] / 5xx D total (sans tiers) / 411 C total tiers
     *   + auto-lettrage interne des 2 lignes 411 (D et C, même tiers, mêmes montants).
     *
     * Résolution du compte de portage selon mode :
     *   Cheque      → 5112 (Chèques à encaisser)
     *   Especes     → 530  (Caisse)
     *   Virement    → $compteTresorerie (512X physique)
     *   Cb          → $compteTresorerie (512X physique, ex. HelloAsso)
     *   Prelevement → $compteTresorerie (512X physique, par symétrie virement)
     *
     * @param  iterable<int, array{compte: Compte, montant: float, operation_id?: ?int, seance?: ?int, notes?: ?string}>  $ventilations
     *
     * @throws \InvalidArgumentException Si total des ventilations ≤ 0 ou si ventilations vides.
     * @throws CompteIncorrectException Si un compte ventilé ∉ classe 7,
     *                                  ou si $compteTresorerie ∉ 512X pour Virement/Cb/Prelevement.
     * @throws TenantBoundaryException Si $tiers ou l'un des comptes n'appartient pas au tenant courant.
     * @throws EcritureNonEquilibreeException Sécurité paranoïaque post-création.
     */
    public function pourRecetteComptant(
        Tiers $tiers,
        iterable $ventilations,
        ModePaiement $mode,
        Compte $compteTresorerie,
        \DateTimeInterface $date,
        ?string $libelle = null,
    ): Transaction {
        // --- Normalisation ventilations ---
        $ventilationsNorm = collect($ventilations);

        if ($ventilationsNorm->isEmpty()) {
            throw new \InvalidArgumentException(
                'Les ventilations ne peuvent pas être vides pour une recette comptant.'
            );
        }

        // --- Validation : chaque compte ventilé est classe 7 ---
        foreach ($ventilationsNorm as $v) {
            /** @var Compte $compteVent */
            $compteVent = $v['compte'];

            if ($compteVent->classe !== 7) {
                throw CompteIncorrectException::classeAttendue(
                    $compteVent->numero_pcg,
                    $compteVent->classe,
                    7
                );
            }
        }

        // --- Calcul du total ---
        $total = (float) $ventilationsNorm->sum(fn (array $v): float => (float) $v['montant']);

        if ($total <= 0) {
            throw new \InvalidArgumentException(
                "Le montant total d'une recette comptant doit être strictement positif (reçu : {$total})."
            );
        }

        // --- Validation : modes nécessitant un compte bancaire physique 512X ---
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

        // --- Résolution du compte de portage (5112 / 530 / 512X) ---
        $comptePortage = $this->resoudreComptePortage($mode, $compteTresorerie);

        // --- Résolution compte 411 (tenant-scopé automatiquement) ---
        $compte411 = Compte::ofNumeroSysteme('411');

        // --- Invariant tenant (avant DB::transaction pour fail-fast) ---
        $this->assertTenantCoherence(
            collect(),
            collect([$tiers])
        );

        // --- Création dans une transaction DB ---
        return DB::transaction(function () use (
            $tiers, $ventilationsNorm, $comptePortage, $compte411, $total, $mode,
            $date, $libelle
        ): Transaction {
            $libelleEffectif = $libelle ?? 'Recette '.$mode->label();

            $transaction = $this->createTransactionHeader(
                type: TypeTransaction::Recette,
                date: $date,
                libelle: $libelleEffectif,
                montant: $total,
                modePaiement: $mode,
            );

            $toutesLignes = [];

            // Ligne 411 D total — tiers (créance immédiate)
            $ligne411D = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compte411->id,
                'debit' => $total,
                'credit' => 0,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne411D->setRelation('compte', $compte411);
            $toutesLignes[] = $ligne411D;

            // N lignes [7x C × N] — sans tiers
            foreach ($ventilationsNorm as $v) {
                /** @var Compte $compteVent */
                $compteVent = $v['compte'];
                $montantVent = (float) $v['montant'];

                $ligneVent = TransactionLigne::create([
                    'transaction_id' => $transaction->id,
                    'compte_id' => $compteVent->id,
                    'debit' => 0,
                    'credit' => $montantVent,
                    'tiers_id' => null,
                    'libelle' => $libelleEffectif,
                    'operation_id' => $v['operation_id'] ?? null,
                    'seance' => $v['seance'] ?? null,
                    'montant' => 0,
                    'sous_categorie_id' => null,
                ]);
                $ligneVent->setRelation('compte', $compteVent);
                $toutesLignes[] = $ligneVent;
            }

            // Ligne portage D total — sans tiers (5112 / 530 / 512X)
            $lignePortage = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $comptePortage->id,
                'debit' => $total,
                'credit' => 0,
                'tiers_id' => null,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $lignePortage->setRelation('compte', $comptePortage);
            $toutesLignes[] = $lignePortage;

            // Ligne 411 C total — tiers (contrepassation immédiate)
            $ligne411C = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compte411->id,
                'debit' => 0,
                'credit' => $total,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne411C->setRelation('compte', $compte411);
            $toutesLignes[] = $ligne411C;

            // --- Auto-lettrage interne des 2 lignes 411 (D et C, même tiers) ---
            $this->lettrageService->lettrer(
                collect([$ligne411D, $ligne411C]),
                null,
                "Auto-lettrage interne recette comptant T#{$transaction->id} tiers #{$tiers->id}"
            );

            // --- Recharger toutes les lignes de T depuis la DB (lettrage_code à jour) ---
            $idsLignes = collect($toutesLignes)->pluck('id')->all();
            $lignes = TransactionLigne::whereIn('id', $idsLignes)->get();

            // Recharger les relations compte sur chaque ligne rechargée
            foreach ($lignes as $l) {
                if ((int) $l->compte_id === (int) $compte411->id) {
                    $l->setRelation('compte', $compte411);
                } elseif ((int) $l->compte_id === (int) $comptePortage->id) {
                    $l->setRelation('compte', $comptePortage);
                } else {
                    // Compte de ventilation (7x) — chercher dans la collection normalisée
                    foreach ($ventilationsNorm as $v) {
                        if ((int) $v['compte']->id === (int) $l->compte_id) {
                            $l->setRelation('compte', $v['compte']);
                            break;
                        }
                    }
                }
            }

            // --- Vérifications post-création (paranoïa) ---
            $this->assertEquilibre($lignes);
            $this->assertTiersObligatoire411($lignes);
            $this->assertPasDeTiersSurClasse5($lignes);

            $transaction->setRelation('lignes', $lignes);

            return $transaction;
        });
    }

    /**
     * Génère l'écriture T1 (créance ouverte) à (N+1) lignes — école 411 systématique.
     *
     * Amendée 2026-05-22 : signature multi-ventilation.
     * Schéma : 411 D total tiers / [7x C × N]
     * Pas de lettrage à ce stade (créance ouverte). Le lettrage est appliqué
     * lors de l'encaissement via pourEncaissementCreance().
     *
     * mode_paiement est null : aucun paiement n'a encore eu lieu.
     *
     * @param  iterable<int, array{compte: Compte, montant: float, operation_id?: ?int, seance?: ?int, notes?: ?string}>  $ventilations
     *
     * @throws \InvalidArgumentException Si total ≤ 0 ou ventilations vides.
     * @throws CompteIncorrectException Si un compte ventilé ∉ classe 7.
     * @throws TenantBoundaryException Si $tiers n'appartient pas au tenant courant.
     * @throws EcritureNonEquilibreeException Sécurité paranoïaque post-création.
     */
    public function pourRecetteACredit(
        Tiers $tiers,
        iterable $ventilations,
        \DateTimeInterface $dateConstatation,
        ?string $libelle = null,
    ): Transaction {
        // --- Normalisation ventilations ---
        $ventilationsNorm = collect($ventilations);

        if ($ventilationsNorm->isEmpty()) {
            throw new \InvalidArgumentException(
                'Les ventilations ne peuvent pas être vides pour une recette à crédit.'
            );
        }

        // --- Validation : chaque compte ventilé est classe 7 ---
        foreach ($ventilationsNorm as $v) {
            /** @var Compte $compteVent */
            $compteVent = $v['compte'];

            if ($compteVent->classe !== 7) {
                throw CompteIncorrectException::classeAttendue(
                    $compteVent->numero_pcg,
                    $compteVent->classe,
                    7
                );
            }
        }

        // --- Calcul du total ---
        $total = (float) $ventilationsNorm->sum(fn (array $v): float => (float) $v['montant']);

        if ($total <= 0) {
            throw new \InvalidArgumentException(
                "Le montant total d'une recette à crédit doit être strictement positif (reçu : {$total})."
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
            $tiers, $ventilationsNorm, $compte411, $total,
            $dateConstatation, $libelle
        ): Transaction {
            $libelleEffectif = $libelle ?? 'Recette à crédit';

            $transaction = $this->createTransactionHeader(
                type: TypeTransaction::Recette,
                date: $dateConstatation,
                libelle: $libelleEffectif,
                montant: $total,
                modePaiement: null,
            );

            $lignes = [];

            // Ligne 411 D total — tiers (créance ouverte)
            $ligne411 = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compte411->id,
                'debit' => $total,
                'credit' => 0,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne411->setRelation('compte', $compte411);
            $lignes[] = $ligne411;

            // N lignes [7x C × N] — sans tiers
            foreach ($ventilationsNorm as $v) {
                /** @var Compte $compteVent */
                $compteVent = $v['compte'];
                $montantVent = (float) $v['montant'];

                $ligneVent = TransactionLigne::create([
                    'transaction_id' => $transaction->id,
                    'compte_id' => $compteVent->id,
                    'debit' => 0,
                    'credit' => $montantVent,
                    'tiers_id' => null,
                    'libelle' => $libelleEffectif,
                    'operation_id' => $v['operation_id'] ?? null,
                    'seance' => $v['seance'] ?? null,
                    'montant' => 0,
                    'sous_categorie_id' => null,
                ]);
                $ligneVent->setRelation('compte', $compteVent);
                $lignes[] = $ligneVent;
            }

            // --- Vérifications post-création (paranoïa) ---
            $lignesCollection = collect($lignes);
            $this->assertEquilibre($lignesCollection);
            $this->assertTiersObligatoire411($lignesCollection);

            $transaction->setRelation('lignes', $lignesCollection);

            return $transaction;
        });
    }

    /**
     * Génère l'écriture T1 (dépense comptant) à (N+3) lignes — école 411 systématique.
     *
     * Amendée 2026-05-22 : signature multi-ventilation, schéma N+3.
     * Matrice école 401 (spec §4.3 amendée) :
     *   [6x D × N] / 401 C total tiers / 401 D total tiers / 5xx C total (sans tiers)
     *   + auto-lettrage interne des 2 lignes 401 (C et D, même tiers, mêmes montants).
     *
     * Asymétrie chèque (conservée) : chèque émis → 512X direct (pas de 5112 miroir).
     * Raison : le 5112 représente les valeurs physiques en main (chèques reçus).
     * Pour un chèque émis, l'asso n'a plus rien en main.
     *
     * @param  iterable<int, array{compte: Compte, montant: float, operation_id?: ?int, seance?: ?int, notes?: ?string}>  $ventilations
     *
     * @throws \InvalidArgumentException Si total des ventilations ≤ 0 ou si ventilations vides.
     * @throws CompteIncorrectException Si un compte ventilé ∉ classe 6,
     *                                  ou si $compteTresorerie ∉ 512X pour Cheque/Cb/Virement/Prelevement.
     * @throws TenantBoundaryException Si $tiers ou l'un des comptes n'appartient pas au tenant courant.
     * @throws EcritureNonEquilibreeException Sécurité paranoïaque post-création.
     */
    public function pourDepenseComptant(
        Tiers $tiers,
        iterable $ventilations,
        ModePaiement $mode,
        Compte $compteTresorerie,
        \DateTimeInterface $date,
        ?string $libelle = null,
    ): Transaction {
        // --- Normalisation ventilations ---
        $ventilationsNorm = collect($ventilations);

        if ($ventilationsNorm->isEmpty()) {
            throw new \InvalidArgumentException(
                'Les ventilations ne peuvent pas être vides pour une dépense comptant.'
            );
        }

        // --- Validation : chaque compte ventilé est classe 6 ---
        foreach ($ventilationsNorm as $v) {
            /** @var Compte $compteVent */
            $compteVent = $v['compte'];

            if ($compteVent->classe !== 6) {
                throw CompteIncorrectException::classeAttendue(
                    $compteVent->numero_pcg,
                    $compteVent->classe,
                    6
                );
            }
        }

        // --- Calcul du total ---
        $total = (float) $ventilationsNorm->sum(fn (array $v): float => (float) $v['montant']);

        if ($total <= 0) {
            throw new \InvalidArgumentException(
                "Le montant total d'une dépense comptant doit être strictement positif (reçu : {$total})."
            );
        }

        // --- Validation : modes nécessitant un compte bancaire physique 512X ---
        $modesNecessitantTresorerie = [
            ModePaiement::Cheque,
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

        // --- Résolution du compte de portage (helper dédié dépenses, asymétrie chèque) ---
        $comptePortage = $this->resoudreComptePortageDepense($mode, $compteTresorerie);

        // --- Résolution compte 401 (tenant-scopé automatiquement) ---
        $compte401 = Compte::ofNumeroSysteme('401');

        // --- Invariant tenant (avant DB::transaction pour fail-fast) ---
        $this->assertTenantCoherence(
            collect(),
            collect([$tiers])
        );

        // --- Création dans une transaction DB ---
        return DB::transaction(function () use (
            $tiers, $ventilationsNorm, $comptePortage, $compte401, $total, $mode,
            $date, $libelle
        ): Transaction {
            $libelleEffectif = $libelle ?? 'Dépense '.$mode->label();

            $transaction = $this->createTransactionHeader(
                type: TypeTransaction::Depense,
                date: $date,
                libelle: $libelleEffectif,
                montant: $total,
                modePaiement: $mode,
            );

            $toutesLignes = [];

            // N lignes [6x D × N] — sans tiers
            foreach ($ventilationsNorm as $v) {
                /** @var Compte $compteVent */
                $compteVent = $v['compte'];
                $montantVent = (float) $v['montant'];

                $ligneVent = TransactionLigne::create([
                    'transaction_id' => $transaction->id,
                    'compte_id' => $compteVent->id,
                    'debit' => $montantVent,
                    'credit' => 0,
                    'tiers_id' => null,
                    'libelle' => $libelleEffectif,
                    'operation_id' => $v['operation_id'] ?? null,
                    'seance' => $v['seance'] ?? null,
                    'montant' => 0,
                    'sous_categorie_id' => null,
                ]);
                $ligneVent->setRelation('compte', $compteVent);
                $toutesLignes[] = $ligneVent;
            }

            // Ligne 401 C total — tiers (constatation dette immédiate)
            $ligne401C = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compte401->id,
                'debit' => 0,
                'credit' => $total,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne401C->setRelation('compte', $compte401);
            $toutesLignes[] = $ligne401C;

            // Ligne 401 D total — tiers (soldage immédiat de la dette)
            $ligne401D = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compte401->id,
                'debit' => $total,
                'credit' => 0,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne401D->setRelation('compte', $compte401);
            $toutesLignes[] = $ligne401D;

            // Ligne portage C total — sans tiers (5xx — trésorerie débitée)
            $lignePortage = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $comptePortage->id,
                'debit' => 0,
                'credit' => $total,
                'tiers_id' => null,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $lignePortage->setRelation('compte', $comptePortage);
            $toutesLignes[] = $lignePortage;

            // --- Auto-lettrage interne des 2 lignes 401 (C et D, même tiers) ---
            $this->lettrageService->lettrer(
                collect([$ligne401C, $ligne401D]),
                null,
                "Auto-lettrage interne dépense comptant T#{$transaction->id} tiers #{$tiers->id}"
            );

            // --- Recharger toutes les lignes depuis la DB (lettrage_code à jour) ---
            $idsLignes = collect($toutesLignes)->pluck('id')->all();
            $lignes = TransactionLigne::whereIn('id', $idsLignes)->get();

            foreach ($lignes as $l) {
                if ((int) $l->compte_id === (int) $compte401->id) {
                    $l->setRelation('compte', $compte401);
                } elseif ((int) $l->compte_id === (int) $comptePortage->id) {
                    $l->setRelation('compte', $comptePortage);
                } else {
                    foreach ($ventilationsNorm as $v) {
                        if ((int) $v['compte']->id === (int) $l->compte_id) {
                            $l->setRelation('compte', $v['compte']);
                            break;
                        }
                    }
                }
            }

            // --- Vérifications post-création (paranoïa) ---
            $this->assertEquilibre($lignes);
            $this->assertTiersObligatoire411($lignes);
            $this->assertPasDeTiersSurClasse5($lignes);

            $transaction->setRelation('lignes', $lignes);

            return $transaction;
        });
    }

    /**
     * Génère l'écriture T2 (encaissement créance) pour une créance client ouverte.
     *
     * Matrice École C (spec §4.3) — Encaissement créance :
     *   T2 : 5112 ou 530 ou 512X D (tiers) / 411 C (tiers)
     *   + auto-lettrage de la paire 411 (ligne T1 + ligne T2)
     *
     * Prérequis : $transactionCreance est une T1 créée par pourRecetteACredit().
     * Elle doit contenir une ligne 411 avec tiers_id non null et non lettrée.
     *
     * @throws \InvalidArgumentException Si T1 ne contient pas de ligne 411 valide.
     * @throws LettrageDejaPresentException Si la ligne 411 source est déjà lettrée.
     * @throws TenantBoundaryException Si les comptes/tiers n'appartiennent pas au tenant courant.
     * @throws EcritureNonEquilibreeException Sécurité paranoïaque post-création.
     */
    public function pourEncaissementCreance(
        Transaction $transactionCreance,
        ModePaiement $mode,
        Compte $compteTresorerie,
        \DateTimeInterface $datePaiement,
        ?string $libelle = null,
    ): Transaction {
        // --- Résolution compte 411 (tenant-scopé automatiquement) ---
        $compte411 = Compte::ofNumeroSysteme('411');

        // --- Résolution ligne 411 source dans T1 (toujours depuis la DB pour avoir l'état frais) ---
        // On requête directement la DB pour garantir que lettrage_code est à jour
        // (la relation en mémoire sur $transactionCreance peut être stale si mise à jour depuis l'extérieur).
        $ligne411Source = TransactionLigne::where('transaction_id', $transactionCreance->id)
            ->where('compte_id', $compte411->id)
            ->first();

        if ($ligne411Source === null || $ligne411Source->tiers_id === null) {
            throw new \InvalidArgumentException(
                "La transaction #{$transactionCreance->id} ne contient pas de ligne 411 avec un tiers — ce n'est pas une créance valide."
            );
        }

        // --- Refus si ligne 411 source déjà lettrée (créance déjà encaissée) ---
        if ($ligne411Source->lettrage_code !== null) {
            throw LettrageDejaPresentException::forLigne(
                (int) $ligne411Source->id,
                $ligne411Source->lettrage_code
            );
        }

        // --- Résolution tiers et compte de portage ---
        /** @var Tiers $tiers */
        $tiers = Tiers::findOrFail($ligne411Source->tiers_id);

        $comptePortage = $this->resoudreComptePortage($mode, $compteTresorerie);

        // --- Invariant tenant (fail-fast avant DB::transaction) ---
        $this->assertTenantCoherence(
            collect(),
            collect([$tiers])
        );

        // --- Montant = débit de la ligne 411 source (créance ouverte) ---
        $montant = (float) $ligne411Source->debit;

        // --- Création dans une transaction DB ---
        return DB::transaction(function () use (
            $transactionCreance, $tiers, $compte411, $comptePortage, $montant,
            $mode, $datePaiement, $libelle, $ligne411Source
        ): Transaction {
            $libelleEffectif = $libelle ?? "Encaissement créance #{$transactionCreance->id}";

            $t2 = $this->createTransactionHeader(
                type: TypeTransaction::Recette,
                date: $datePaiement,
                libelle: $libelleEffectif,
                montant: $montant,
                modePaiement: $mode,
            );

            // Ligne 1 : débit portage (5112 / 530 / 512X) SANS tiers — FEC-conformité
            // Le tiers vit exclusivement sur les comptes 411/401 (école 411 systématique).
            $lignePortage = TransactionLigne::create([
                'transaction_id' => $t2->id,
                'compte_id' => $comptePortage->id,
                'debit' => $montant,
                'credit' => 0,
                'tiers_id' => null,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $lignePortage->setRelation('compte', $comptePortage);

            // Ligne 2 : crédit 411 avec tiers
            $ligne411Encaissement = TransactionLigne::create([
                'transaction_id' => $t2->id,
                'compte_id' => $compte411->id,
                'debit' => 0,
                'credit' => $montant,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne411Encaissement->setRelation('compte', $compte411);

            // --- Vérifications post-création (paranoïa) ---
            $lignes = collect([$lignePortage, $ligne411Encaissement]);
            $this->assertEquilibre($lignes);
            $this->assertTiersObligatoire411($lignes);
            $this->assertPasDeTiersSurClasse5($lignes);

            // --- Auto-lettrage paire 411 : T1-ligne411 ↔ T2-ligne411 ---
            $this->lettrageService->lettrer(
                collect([$ligne411Source, $ligne411Encaissement]),
                null,
                "Auto-lettrage encaissement créance T1#{$transactionCreance->id} → T2#{$t2->id}"
            );

            $t2->setRelation('lignes', $lignes);

            return $t2;
        });
    }

    /**
     * Génère l'écriture T1 (dette fournisseur) à (N+1) lignes — école 411 systématique.
     *
     * Amendée 2026-05-22 : signature multi-ventilation.
     * Schéma : [6x D × N] / 401 C total tiers
     * Pas de lettrage à ce stade (dette ouverte). Le lettrage est appliqué
     * lors du règlement via pourReglementFournisseur().
     *
     * mode_paiement est null : aucun décaissement n'a encore eu lieu.
     *
     * @param  iterable<int, array{compte: Compte, montant: float, operation_id?: ?int, seance?: ?int, notes?: ?string}>  $ventilations
     *
     * @throws \InvalidArgumentException Si total ≤ 0 ou ventilations vides.
     * @throws CompteIncorrectException Si un compte ventilé ∉ classe 6.
     * @throws TenantBoundaryException Si $tiers n'appartient pas au tenant courant.
     * @throws EcritureNonEquilibreeException Sécurité paranoïaque post-création.
     */
    public function pourDepenseACredit(
        Tiers $tiers,
        iterable $ventilations,
        \DateTimeInterface $dateConstatation,
        ?string $libelle = null,
    ): Transaction {
        // --- Normalisation ventilations ---
        $ventilationsNorm = collect($ventilations);

        if ($ventilationsNorm->isEmpty()) {
            throw new \InvalidArgumentException(
                'Les ventilations ne peuvent pas être vides pour une dépense à crédit.'
            );
        }

        // --- Validation : chaque compte ventilé est classe 6 ---
        foreach ($ventilationsNorm as $v) {
            /** @var Compte $compteVent */
            $compteVent = $v['compte'];

            if ($compteVent->classe !== 6) {
                throw CompteIncorrectException::classeAttendue(
                    $compteVent->numero_pcg,
                    $compteVent->classe,
                    6
                );
            }
        }

        // --- Calcul du total ---
        $total = (float) $ventilationsNorm->sum(fn (array $v): float => (float) $v['montant']);

        if ($total <= 0) {
            throw new \InvalidArgumentException(
                "Le montant total d'une dépense à crédit doit être strictement positif (reçu : {$total})."
            );
        }

        // --- Résolution compte 401 (tenant-scopé automatiquement) ---
        $compte401 = Compte::ofNumeroSysteme('401');

        // --- Invariant tenant (fail-fast avant DB::transaction) ---
        $this->assertTenantCoherence(
            collect(),
            collect([$tiers])
        );

        // --- Création dans une transaction DB ---
        return DB::transaction(function () use (
            $tiers, $ventilationsNorm, $compte401, $total,
            $dateConstatation, $libelle
        ): Transaction {
            $libelleEffectif = $libelle ?? 'Dépense à crédit';

            $transaction = $this->createTransactionHeader(
                type: TypeTransaction::Depense,
                date: $dateConstatation,
                libelle: $libelleEffectif,
                montant: $total,
                modePaiement: null,
            );

            $lignes = [];

            // N lignes [6x D × N] — sans tiers
            foreach ($ventilationsNorm as $v) {
                /** @var Compte $compteVent */
                $compteVent = $v['compte'];
                $montantVent = (float) $v['montant'];

                $ligneVent = TransactionLigne::create([
                    'transaction_id' => $transaction->id,
                    'compte_id' => $compteVent->id,
                    'debit' => $montantVent,
                    'credit' => 0,
                    'tiers_id' => null,
                    'libelle' => $libelleEffectif,
                    'operation_id' => $v['operation_id'] ?? null,
                    'seance' => $v['seance'] ?? null,
                    'montant' => 0,
                    'sous_categorie_id' => null,
                ]);
                $ligneVent->setRelation('compte', $compteVent);
                $lignes[] = $ligneVent;
            }

            // Ligne 401 C total — tiers (dette ouverte)
            $ligne401 = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compte401->id,
                'debit' => 0,
                'credit' => $total,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne401->setRelation('compte', $compte401);
            $lignes[] = $ligne401;

            // --- Vérifications post-création (paranoïa) ---
            $lignesCollection = collect($lignes);
            $this->assertEquilibre($lignesCollection);
            $this->assertTiersObligatoire411($lignesCollection);

            $transaction->setRelation('lignes', $lignesCollection);

            return $transaction;
        });
    }

    /**
     * Génère l'écriture T2 (règlement fournisseur) pour une dette fournisseur ouverte.
     *
     * Matrice École C (spec §4.3) — Règlement fournisseur :
     *   T2 : 401 D X (tiers) / $comptePortage C X (tiers)
     *   + auto-lettrage de la paire 401 (ligne T1 + ligne T2)
     *
     * Mapping mode → compte portage (symétrique à pourDepenseComptant) :
     *   Cheque      → $compteTresorerie (512X — chèque émis, pas de 5112 miroir)
     *   Especes     → 530 (Caisse)
     *   Virement    → $compteTresorerie (512X)
     *   Cb          → $compteTresorerie (512X)
     *   Prelevement → $compteTresorerie (512X)
     *
     * Prérequis : $transactionDette est une T1 créée par pourDepenseACredit().
     * Elle doit contenir une ligne 401 avec tiers_id non null et non lettrée.
     *
     * @throws \InvalidArgumentException Si T1 ne contient pas de ligne 401 valide.
     * @throws LettrageDejaPresentException Si la ligne 401 source est déjà lettrée.
     * @throws TenantBoundaryException Si les comptes/tiers n'appartiennent pas au tenant courant.
     * @throws EcritureNonEquilibreeException Sécurité paranoïaque post-création.
     */
    public function pourReglementFournisseur(
        Transaction $transactionDette,
        ModePaiement $mode,
        Compte $compteTresorerie,
        \DateTimeInterface $datePaiement,
        ?string $libelle = null,
    ): Transaction {
        // --- Résolution compte 401 (tenant-scopé automatiquement) ---
        $compte401 = Compte::ofNumeroSysteme('401');

        // --- Résolution ligne 401 source dans T1 (DB fraîche pour lettrage_code à jour) ---
        $ligne401Source = TransactionLigne::where('transaction_id', $transactionDette->id)
            ->where('compte_id', $compte401->id)
            ->first();

        if ($ligne401Source === null || $ligne401Source->tiers_id === null) {
            throw new \InvalidArgumentException(
                "La transaction #{$transactionDette->id} ne contient pas de ligne 401 avec un tiers — ce n'est pas une dette fournisseur valide."
            );
        }

        // --- Refus si ligne 401 source déjà lettrée (dette déjà réglée) ---
        if ($ligne401Source->lettrage_code !== null) {
            throw LettrageDejaPresentException::forLigne(
                (int) $ligne401Source->id,
                $ligne401Source->lettrage_code
            );
        }

        // --- Résolution tiers et compte de portage ---
        /** @var Tiers $tiers */
        $tiers = Tiers::findOrFail($ligne401Source->tiers_id);

        $comptePortage = $this->resoudreComptePortageDepense($mode, $compteTresorerie);

        // --- Invariant tenant (fail-fast avant DB::transaction) ---
        $this->assertTenantCoherence(
            collect(),
            collect([$tiers])
        );

        // --- Montant = crédit de la ligne 401 source (dette ouverte) ---
        $montant = (float) $ligne401Source->credit;

        // --- Création dans une transaction DB ---
        return DB::transaction(function () use (
            $transactionDette, $tiers, $compte401, $comptePortage, $montant,
            $mode, $datePaiement, $libelle, $ligne401Source
        ): Transaction {
            $libelleEffectif = $libelle ?? "Règlement fournisseur #{$transactionDette->id}";

            $t2 = $this->createTransactionHeader(
                type: TypeTransaction::Depense,
                date: $datePaiement,
                libelle: $libelleEffectif,
                montant: $montant,
                modePaiement: $mode,
            );

            // Ligne 1 : débit 401 (tiers — soldage de la dette)
            $ligne401Reglement = TransactionLigne::create([
                'transaction_id' => $t2->id,
                'compte_id' => $compte401->id,
                'debit' => $montant,
                'credit' => 0,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne401Reglement->setRelation('compte', $compte401);

            // Ligne 2 : crédit trésorerie (tiers — décaissement effectif)
            $lignePortage = TransactionLigne::create([
                'transaction_id' => $t2->id,
                'compte_id' => $comptePortage->id,
                'debit' => 0,
                'credit' => $montant,
                'tiers_id' => $tiers->id,
                'libelle' => $libelleEffectif,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $lignePortage->setRelation('compte', $comptePortage);

            // --- Vérifications post-création (paranoïa) ---
            $lignes = collect([$ligne401Reglement, $lignePortage]);
            $this->assertEquilibre($lignes);
            $this->assertTiersObligatoire411($lignes);

            // --- Auto-lettrage paire 401 : T1-ligne401 ↔ T2-ligne401 ---
            $this->lettrageService->lettrer(
                collect([$ligne401Source, $ligne401Reglement]),
                null,
                "Auto-lettrage règlement fournisseur dette T#{$transactionDette->id} → T#{$t2->id}"
            );

            $t2->setRelation('lignes', $lignes);

            return $t2;
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
     * Centralise les champs invariants : association_id, equilibree=true,
     * type_ecriture='normale', saisi_par. mode_paiement peut être null (recette à crédit).
     *
     * Refactoré au Step 18 pour accepter TypeTransaction explicitement (avant : hardcodé
     * Recette). Les appelants Steps 15/16/17 passent TypeTransaction::Recette ; Step 18
     * passe TypeTransaction::Depense.
     */
    private function createTransactionHeader(
        TypeTransaction $type,
        \DateTimeInterface $date,
        string $libelle,
        float $montant,
        ?ModePaiement $modePaiement,
        string $typeEcriture = 'normale',
    ): Transaction {
        return Transaction::create([
            'association_id' => (int) TenantContext::currentId(),
            'type' => $type,
            'date' => $date->format('Y-m-d'),
            'libelle' => $libelle,
            'montant_total' => $montant,
            'mode_paiement' => $modePaiement,
            'saisi_par' => Auth::id(),
            'equilibree' => true,
            'type_ecriture' => $typeEcriture,
        ]);
    }

    /**
     * Résout le compte de portage pour les DÉPENSES selon le mode de paiement.
     *
     * Chèque émis → $compteTresorerieExplicite (512X) — PAS de 5112 miroir.
     *   Raison : le 5112 représente les valeurs physiques en main (chèques reçus).
     *   Pour un chèque émis, l'asso n'a plus rien en main — l'attente du débit
     *   bancaire est gérée par le statut "non pointé" du rapprochement.
     * Espèces     → Compte::ofNumeroSysteme('530')
     * Virement    → $compteTresorerieExplicite
     * Cb          → $compteTresorerieExplicite
     * Prelevement → $compteTresorerieExplicite
     *
     * Séparé de resoudreComptePortage() (recettes) pour éviter un paramètre bool
     * ambigu — les mappings sont sémantiquement différents (chèque reçu ≠ chèque émis).
     */
    private function resoudreComptePortageDepense(ModePaiement $mode, Compte $compteTresorerieExplicite): Compte
    {
        return match ($mode) {
            ModePaiement::Especes => Compte::ofNumeroSysteme('530'),
            ModePaiement::Cheque,
            ModePaiement::Virement,
            ModePaiement::Cb,
            ModePaiement::Prelevement => $compteTresorerieExplicite,
        };
    }

    /**
     * Génère l'écriture T4 (remise bancaire, Variante 2a splittée par tiers) pour une remise
     * groupant plusieurs chèques ou espèces de tiers distincts.
     *
     * Matrice École C (spec §4.3 + §4.4) — Remise bancaire :
     *   T4 : 512X D (total, sans tiers) / N × 5112 (ou 530) C (sous-total tiers, avec tiers)
     *   + auto-lettrage par groupe-tiers (lignes 5112 sources + ligne 5112 T4 du tiers)
     *
     * Variante 2a : splittage par tiers — 1 ligne de portage crédit par tiers groupé.
     * La ligne 512 débit est unique (total agrégé), sans tiers — c'est exactement l'invariant
     * assertPasDeTiersSur512 exercé en context réel.
     *
     * Mode supporté :
     *   Cheque  → portage = 5112 (Chèques à encaisser)
     *   Especes → portage = 530  (Caisse)
     *   Autres  → \InvalidArgumentException
     *
     * @param  RemiseBancaire  $remise  La remise bancaire source (mode_paiement + compte_cible_id).
     * @param  Collection<int, TransactionLigne>  $lignes5112Sources  Lignes sur 5112 ou 530,
     *                                                                toutes avec tiers_id non null,
     *                                                                non lettrées.
     * @return Transaction T4 créée avec N+1 lignes équilibrées.
     *
     * @throws \InvalidArgumentException Si $lignes5112Sources est vide,
     *                                   ou si une ligne source est sans tiers_id,
     *                                   ou si les lignes sources sont sur des comptes différents,
     *                                   ou si le mode de la remise n'est pas Cheque/Especes.
     * @throws CompteIncorrectException Si le compte cible résolu n'est pas un 512X bancaire physique.
     * @throws TenantBoundaryException Si une ligne source ou le compte cible n'appartient pas au tenant courant.
     * @throws LettrageDejaPresentException Si une ligne source est déjà lettrée (levée par LettrageService, rollback).
     * @throws EcritureNonEquilibreeException Sécurité paranoïaque post-création.
     */
    public function pourRemiseBancaire(
        RemiseBancaire $remise,
        Collection $lignes5112Sources,
    ): Transaction {
        // --- Validation : lignes non vides ---
        if ($lignes5112Sources->isEmpty()) {
            throw new \InvalidArgumentException(
                'La collection de lignes sources pour la remise bancaire ne peut pas être vide.'
            );
        }

        // --- Validation : toutes les lignes sources ont un tiers_id ---
        foreach ($lignes5112Sources as $ligne) {
            if ($ligne->tiers_id === null) {
                throw new \InvalidArgumentException(
                    "La ligne #{$ligne->id} n'a pas de tiers_id — une remise bancaire ne peut pas avoir de lignes anonymes."
                );
            }
        }

        // --- Validation : toutes les lignes sources sont sur le même compte ---
        $compteIdsSources = $lignes5112Sources->pluck('compte_id')->unique();
        if ($compteIdsSources->count() > 1) {
            throw new \InvalidArgumentException(
                'Les lignes sources de la remise bancaire sont sur des comptes différents ('.
                $compteIdsSources->implode(', ').') — elles doivent toutes être sur le même compte de portage.'
            );
        }

        // --- Validation : mode supporté (Cheque ou Especes) ---
        $mode = $remise->mode_paiement;
        if ($mode !== ModePaiement::Cheque && $mode !== ModePaiement::Especes) {
            throw new \InvalidArgumentException(
                "Le mode {$mode->value} n'est pas supporté pour une remise bancaire. Modes acceptés : cheque, especes."
            );
        }

        // --- Résolution du compte de portage (5112 ou 530) ---
        $comptePortage = $this->resoudreComptePortage($mode, Compte::ofNumeroSysteme('5112'));
        // Note : pour Cheque → resoudreComptePortage retourne 5112
        //        pour Especes → resoudreComptePortage retourne 530
        // Le 3e argument (compteTresorerieExplicite) n'est jamais utilisé pour ces 2 modes.

        // --- Résolution du compte cible (512X) depuis RemiseBancaire → CompteBancaire → Compte ---
        // Jointure par compte_bancaire_id (clé stable) : l'IBAN est nullable et
        // non unique, il ne peut pas servir de clé de résolution.
        $compteBancaire = $remise->compteCible;
        $compteCible512 = Compte::where('compte_bancaire_id', $compteBancaire->id)
            ->where('association_id', (int) TenantContext::currentId())
            ->first();

        if ($compteCible512 === null) {
            throw new \InvalidArgumentException(
                "Aucun compte 512X trouvé pour le CompteBancaire #{$compteBancaire->id}."
            );
        }

        // --- Validation : compte cible doit être un 512X bancaire physique ---
        $isBancaire512 = $compteCible512->classe === 5
            && str_starts_with($compteCible512->numero_pcg, '512')
            && strlen($compteCible512->numero_pcg) > 3;

        if (! $isBancaire512) {
            throw CompteIncorrectException::classeAttendue(
                $compteCible512->numero_pcg,
                $compteCible512->classe,
                '5 (512X — bancaire physique)'
            );
        }

        // --- Invariant tenant (fail-fast avant DB::transaction) ---
        $this->assertTenantCoherence($lignes5112Sources);

        // --- Recharger les lignes sources depuis la DB pour avoir lettrage_code à jour ---
        // Les objets passés peuvent être stale si leur lettrage_code a été modifié entre
        // leur création et cet appel. LettrageService::assertPasDeRelettrage vérifie
        // l'objet PHP → on garantit l'état frais en rechargeant depuis la DB.
        $ids = $lignes5112Sources->pluck('id')->all();
        $lignesSourcesFraiches = TransactionLigne::whereIn('id', $ids)->get();

        // --- Calcul du total et groupement par tiers (sur les lignes fraîches) ---
        $total = (float) $lignesSourcesFraiches->sum(fn (TransactionLigne $l): float => (float) $l->debit);
        $groupesParTiers = $this->regrouperParTiers($lignesSourcesFraiches);

        // --- Création dans une transaction DB ---
        return DB::transaction(function () use (
            $remise, $comptePortage, $compteCible512,
            $total, $groupesParTiers, $mode
        ): Transaction {
            $libelle = $remise->libelle ?? "Remise bancaire #{$remise->id}";

            $t4 = $this->createTransactionHeader(
                type: TypeTransaction::Recette,
                date: $remise->date instanceof \DateTimeInterface
                    ? $remise->date
                    : new \DateTimeImmutable((string) $remise->date),
                libelle: $libelle,
                montant: $total,
                modePaiement: $mode,
            );

            // --- Ligne 512X D total, SANS tiers ---
            $ligne512 = TransactionLigne::create([
                'transaction_id' => $t4->id,
                'compte_id' => $compteCible512->id,
                'debit' => $total,
                'credit' => 0,
                'tiers_id' => null,
                'libelle' => $libelle,
                'montant' => 0,
                'sous_categorie_id' => null,
            ]);
            $ligne512->setRelation('compte', $compteCible512);

            // --- N lignes portage crédit (une par tiers groupé), AVEC tiers ---
            $idsLignesT4 = [$ligne512->id];

            foreach ($groupesParTiers as $groupe) {
                $tiersId = $groupe['tiers_id'];
                $sousTotal = $groupe['sousTotal'];
                $lignesSourcesTiers = $groupe['lignes'];

                $lignePortageTiers = TransactionLigne::create([
                    'transaction_id' => $t4->id,
                    'compte_id' => $comptePortage->id,
                    'debit' => 0,
                    'credit' => $sousTotal,
                    'tiers_id' => $tiersId,
                    'libelle' => $libelle,
                    'montant' => 0,
                    'sous_categorie_id' => null,
                ]);
                $idsLignesT4[] = $lignePortageTiers->id;

                // --- Auto-lettrage par groupe-tiers ---
                // Lettrer : lignes sources fraîches du tiers + ligne T4 crédit du tiers
                // lignesSourcesTiers->push() crée une nouvelle collection (immutable-like)
                $this->lettrageService->lettrer(
                    $lignesSourcesTiers->values()->push($lignePortageTiers),
                    null,
                    "Auto-lettrage remise bancaire #{$remise->id} pour tiers #{$tiersId}"
                );
            }

            // --- Recharger toutes les lignes de T4 depuis la DB (lettrage_code à jour) ---
            $lignesT4 = TransactionLigne::whereIn('id', $idsLignesT4)->get();
            foreach ($lignesT4 as $l) {
                if ((int) $l->compte_id === (int) $compteCible512->id) {
                    $l->setRelation('compte', $compteCible512);
                } else {
                    $l->setRelation('compte', $comptePortage);
                }
            }

            // --- Vérifications post-création (paranoïa) ---
            $this->assertEquilibre($lignesT4);

            // assertPasDeTiersSurClasse5 : exercé sur la ligne 512 D (sans tiers, voulu).
            // NB : à la révision Step 20 (école 411 systématique amendée 2026-05-22),
            // les lignes 5112 C de remise n'auront plus de tiers non plus, et l'invariant
            // pourra être exercé sur l'ensemble des lignes T4.
            $ligne512Rechargee = $lignesT4->firstWhere('compte_id', $compteCible512->id);
            $this->assertPasDeTiersSurClasse5(collect([$ligne512Rechargee]));

            $t4->setRelation('lignes', $lignesT4);

            return $t4;
        });
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

    // =========================================================================
    // Helpers privés — Remise bancaire
    // =========================================================================

    /**
     * Regroupe les lignes de portage sources par tiers_id et calcule le sous-total.
     *
     * @param  Collection<int, TransactionLigne>  $lignes
     * @return Collection<int, array{tiers_id: int, lignes: Collection<int, TransactionLigne>, sousTotal: float}>
     */
    private function regrouperParTiers(Collection $lignes): Collection
    {
        return $lignes
            ->groupBy(fn (TransactionLigne $l): int => (int) $l->tiers_id)
            ->map(function (Collection $group, int $tiersId): array {
                $sousTotal = (float) $group->sum(fn (TransactionLigne $l): float => (float) $l->debit);

                return [
                    'tiers_id' => $tiersId,
                    'lignes' => $group,
                    'sousTotal' => $sousTotal,
                ];
            })
            ->values();
    }
}
