<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\DTOs\Compta\PosteTiersOuvert;
use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use App\Support\MontantDecimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class PosteTiersReglementService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly PostesTiersOuvertsService $postesOuverts,
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly EtatReglementResolver $etatReglementResolver,
        private readonly LettrageService $lettrageService,
    ) {}

    public function regler(PosteTiersReglementData $data): Transaction
    {
        return $this->executer(
            ligneId: $data->ligneId,
            montantCentimes: $data->montantCentimes,
            date: $data->date,
            mode: $data->mode,
            compteBancaireId: $data->compteBancaireId,
            exercice: $data->exercice,
        );
    }

    public function reglerReliquat(
        int $ligneId,
        CarbonImmutable $date,
        ModePaiement $mode,
        ?int $compteBancaireId,
        int $exercice,
    ): Transaction {
        return $this->executer(
            ligneId: $ligneId,
            montantCentimes: null,
            date: $date,
            mode: $mode,
            compteBancaireId: $compteBancaireId,
            exercice: $exercice,
        );
    }

    /**
     * Annule un règlement T2 non rapproché et restaure le poste tiers ouvert.
     */
    public function annuler(int $transactionReglementId): void
    {
        DB::transaction(function () use ($transactionReglementId): void {
            $t2 = Transaction::query()
                ->whereKey($transactionReglementId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->exerciceService->assertOuvertVerrouille(
                $this->exerciceService->anneeForDate(CarbonImmutable::parse($t2->date))
            );

            if ($t2->rapprochement_id !== null) {
                throw new RuntimeException(
                    'Ce règlement est lié à un rapprochement bancaire et ne peut pas être annulé.'
                );
            }

            if ($t2->remise_id !== null) {
                throw new RuntimeException(
                    'Ce règlement est lié à une remise bancaire et ne peut pas être annulé.'
                );
            }

            /** @var Collection<int, TransactionLigne> $lignesT2 */
            $lignesT2 = TransactionLigne::query()
                ->with('compte')
                ->where('transaction_id', (int) $t2->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lignesT2->isEmpty()) {
                throw new RuntimeException('Le règlement ne contient aucune ligne comptable à annuler.');
            }

            $portageLettre = $lignesT2->first(function (TransactionLigne $ligne): bool {
                return in_array($ligne->compte?->numero_pcg, ['5112', '530'], true)
                    && $ligne->lettrage_code !== null;
            });
            if ($portageLettre !== null) {
                throw new RuntimeException(
                    'Le règlement a déjà été remis en banque ou rapproché et ne peut pas être annulé.'
                );
            }

            $ligneTiersT2 = $lignesT2->first(function (TransactionLigne $ligne): bool {
                return in_array($ligne->compte?->numero_pcg, ['401', '411'], true)
                    && $ligne->lettrage_code !== null;
            });
            if ($ligneTiersT2 === null) {
                throw new RuntimeException('Le règlement ne possède pas de ligne tiers lettrée à annuler.');
            }

            /** @var Collection<int, TransactionLigne> $groupeLettrage */
            $groupeLettrage = TransactionLigne::query()
                ->with(['compte', 'transaction'])
                ->withTrashed()
                ->where('compte_id', (int) $ligneTiersT2->compte_id)
                ->where('lettrage_code', $ligneTiersT2->lettrage_code)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($groupeLettrage->count() !== 2) {
                throw new RuntimeException(
                    'Le lettrage du règlement doit contenir exactement deux lignes tiers.'
                );
            }

            $lignesT2DuGroupe = $groupeLettrage->filter(
                fn (TransactionLigne $ligne): bool => (int) $ligne->transaction_id === (int) $t2->id
            );
            if ($lignesT2DuGroupe->count() !== 1
                || (int) $lignesT2DuGroupe->first()->id !== (int) $ligneTiersT2->id) {
                throw new RuntimeException(
                    'Le lettrage du règlement ne référence pas une unique ligne tiers de la T2.'
                );
            }

            $lignePaire = $groupeLettrage->first(
                fn (TransactionLigne $ligne): bool => (int) $ligne->id !== (int) $ligneTiersT2->id
            );
            if ($lignePaire === null
                || (int) $lignePaire->transaction_id === (int) $t2->id
                || (int) $lignePaire->compte_id !== (int) $ligneTiersT2->compte_id
                || ! $this->memesTiers($lignePaire, $ligneTiersT2)) {
                throw new RuntimeException('La contrepartie tiers du règlement est invalide.');
            }

            $ligneCanoniqueId = (int) ($lignePaire->poste_tiers_parent_id ?? $lignePaire->id);
            $parent = null;
            if ($lignePaire->poste_tiers_parent_id !== null) {
                $parent = TransactionLigne::query()
                    ->withTrashed()
                    ->whereKey((int) $lignePaire->poste_tiers_parent_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $parent->transaction_id !== (int) $lignePaire->transaction_id
                    || (int) $parent->compte_id !== (int) $lignePaire->compte_id
                    || ! $this->memesTiers($parent, $lignePaire)
                    || $parent->poste_tiers_parent_id !== null) {
                    throw new RuntimeException(
                        'La contrepartie tiers du règlement ne respecte pas la filiation attendue.'
                    );
                }
            }

            $this->lettrageService->delettrerParLigne(
                $ligneTiersT2,
                "Annulation du règlement T2 #{$t2->id}"
            );

            TransactionLigne::query()
                ->whereIn('id', $lignesT2->pluck('id')->map(static fn ($id): int => (int) $id)->all())
                ->forceDelete();
            $t2->forceDelete();

            if ($parent !== null && $parent->lettrage_code === null) {
                $montantFusionne = $this->montantCentimes($parent) + $this->montantCentimes($lignePaire);
                $parent->update($this->montantsSelonSens($parent, $montantFusionne));
                $lignePaire->delete();
            }

            $poste = $this->postesOuverts->trouver(
                $ligneCanoniqueId,
                $this->exerciceService->anneeForDate(CarbonImmutable::parse($t2->date))
            );
            $transactionRacine = Transaction::query()
                ->findOrFail($poste->transactionOrigineId);
            $this->etatReglementResolver->syncer($transactionRacine);
        }, 3);
    }

    /**
     * Solde le reliquat depuis une transaction historique dont le mode et le
     * compte bancaire doivent être relus sous verrou.
     *
     * @return Transaction|null Null si aucun mode de paiement n'est enregistré.
     */
    public function reglerReliquatDepuisTransaction(
        int $ligneId,
        int $transactionSourceId,
        CarbonImmutable $date,
        int $exercice,
    ): ?Transaction {
        return $this->executerDepuisTransaction(
            ligneId: $ligneId,
            transactionSourceId: $transactionSourceId,
            date: $date,
            modePropose: null,
            compteBancaireIdPropose: null,
            exercice: $exercice,
            exigerTransactionEnAttente: false,
        );
    }

    /**
     * Solde le reliquat et renseigne atomiquement le mode/compte de la T1.
     *
     * Les valeurs proposées ne remplacent jamais un mode déjà enregistré :
     * chaque retry relit la transaction sous verrou et recalcule les valeurs
     * effectives à partir de l'état courant.
     *
     * @return Transaction|null Null si la transaction n'est plus éligible ou
     *                          si aucun mode effectif n'est disponible.
     */
    public function reglerReliquatEtRenseignerTransaction(
        int $ligneId,
        int $transactionSourceId,
        CarbonImmutable $date,
        ?ModePaiement $modePropose,
        ?int $compteBancaireIdPropose,
        int $exercice,
    ): ?Transaction {
        return $this->executerDepuisTransaction(
            ligneId: $ligneId,
            transactionSourceId: $transactionSourceId,
            date: $date,
            modePropose: $modePropose,
            compteBancaireIdPropose: $compteBancaireIdPropose,
            exercice: $exercice,
            exigerTransactionEnAttente: true,
        );
    }

    private function executer(
        int $ligneId,
        ?int $montantCentimes,
        CarbonImmutable $date,
        ModePaiement $mode,
        ?int $compteBancaireId,
        int $exercice,
    ): Transaction {
        $ligneCanoniqueId = $this->resoudreLigneCanoniqueId($ligneId);

        return DB::transaction(function () use (
            $ligneCanoniqueId,
            $montantCentimes,
            $date,
            $mode,
            $compteBancaireId,
            $exercice,
        ): Transaction {
            $this->exerciceService->assertOuvertVerrouille($exercice);
            $this->assertDateDansExercice($date, $exercice);

            $lignesVerrouillees = $this->verrouillerLotCanonique($ligneCanoniqueId);
            $this->assertCompteBancaireDuTenant($compteBancaireId);

            return $this->creerReglementVerrouille(
                ligneCanoniqueId: $ligneCanoniqueId,
                lignesVerrouillees: $lignesVerrouillees,
                montantCentimes: $montantCentimes,
                date: $date,
                mode: $mode,
                compteBancaireId: $compteBancaireId,
                exercice: $exercice,
            );
        }, 3);
    }

    private function executerDepuisTransaction(
        int $ligneId,
        int $transactionSourceId,
        CarbonImmutable $date,
        ?ModePaiement $modePropose,
        ?int $compteBancaireIdPropose,
        int $exercice,
        bool $exigerTransactionEnAttente,
    ): ?Transaction {
        $ligneCanoniqueId = $this->resoudreLigneCanoniqueId($ligneId);

        return DB::transaction(function () use (
            $ligneCanoniqueId,
            $transactionSourceId,
            $date,
            $modePropose,
            $compteBancaireIdPropose,
            $exercice,
            $exigerTransactionEnAttente,
        ): ?Transaction {
            $this->exerciceService->assertOuvertVerrouille($exercice);
            $this->assertDateDansExercice($date, $exercice);

            $lignesVerrouillees = $this->verrouillerLotCanonique($ligneCanoniqueId);
            $transactionSource = Transaction::query()
                ->whereKey($transactionSourceId)
                ->lockForUpdate()
                ->firstOrFail();
            $poste = $this->postesOuverts->trouver($ligneCanoniqueId, $exercice);

            if ((int) $transactionSource->id !== (int) $poste->transactionOrigineId) {
                throw new DomainException(
                    'La transaction source ne correspond pas au poste tiers à régler.'
                );
            }

            if ($exigerTransactionEnAttente
                && (
                    $transactionSource->statut_reglement !== StatutReglement::EnAttente
                    || $transactionSource->isLockedByRapprochement()
                    || $transactionSource->isLockedByFacture()
                )) {
                return null;
            }

            $modeEffectif = $transactionSource->mode_paiement ?? $modePropose;
            if ($modeEffectif === null) {
                return null;
            }

            $compteBancaireIdEffectif = $transactionSource->compte_id;
            $doitRenseignerSource = $transactionSource->mode_paiement === null && $modePropose !== null;
            if ($doitRenseignerSource && $compteBancaireIdPropose !== null) {
                $compteBancaireIdEffectif = $compteBancaireIdPropose;
            }

            $this->assertCompteBancaireDuTenant(
                $compteBancaireIdEffectif !== null ? (int) $compteBancaireIdEffectif : null
            );

            $t2 = $this->creerReglementVerrouille(
                ligneCanoniqueId: $ligneCanoniqueId,
                lignesVerrouillees: $lignesVerrouillees,
                montantCentimes: null,
                date: $date,
                mode: $modeEffectif,
                compteBancaireId: $compteBancaireIdEffectif !== null
                    ? (int) $compteBancaireIdEffectif
                    : null,
                exercice: $exercice,
            );

            if ($doitRenseignerSource) {
                $transactionUpdate = ['mode_paiement' => $modeEffectif->value];
                if ($compteBancaireIdPropose !== null) {
                    $transactionUpdate['compte_id'] = $compteBancaireIdPropose;
                }

                $transactionSource->update($transactionUpdate);
            }

            return $t2;
        }, 3);
    }

    private function resoudreLigneCanoniqueId(int $ligneId): int
    {
        // Lecture sans verrou avant la transaction : chaque retry verrouille
        // ensuite la racine et toutes ses fractions dans l'ordre des IDs.
        $ligneReference = TransactionLigne::query()
            ->withTrashed()
            ->findOrFail($ligneId);

        return (int) ($ligneReference->poste_tiers_parent_id ?? $ligneReference->id);
    }

    /**
     * @param  Collection<int, TransactionLigne>  $lignesVerrouillees
     */
    private function creerReglementVerrouille(
        int $ligneCanoniqueId,
        Collection $lignesVerrouillees,
        ?int $montantCentimes,
        CarbonImmutable $date,
        ModePaiement $mode,
        ?int $compteBancaireId,
        int $exercice,
    ): Transaction {
        $poste = $this->postesOuverts->trouver($ligneCanoniqueId, $exercice);
        $montantEffectif = $montantCentimes ?? $poste->soldeCentimes;
        $this->assertMontant($montantEffectif, $poste->soldeCentimes);

        $ligneALettrer = $this->preparerPartPayee(
            $poste,
            $montantEffectif,
            $lignesVerrouillees
                ->filter(fn (TransactionLigne $ligne): bool => $ligne->deleted_at === null)
                ->whereNull('lettrage_code')
                ->values(),
        );
        $compteTresorerie = $this->resoudreCompteTresorerie(
            $ligneALettrer,
            $mode,
            $compteBancaireId,
        );
        $t1 = Transaction::findOrFail($poste->transactionOrigineId);

        $t2 = $this->ecritureGenerator->pourReglement(
            t1: $t1,
            mode: $mode,
            compteTresorerie: $compteTresorerie,
            datePaiement: $date,
            ligneTiersSource: $ligneALettrer,
            heriterCompteBancaireSource: false,
        );

        if ($t2->compte_id !== $compteBancaireId) {
            $t2->update(['compte_id' => $compteBancaireId]);
        }

        $this->etatReglementResolver->syncer($t1->fresh());

        return $t2;
    }

    private function assertCompteBancaireDuTenant(?int $compteBancaireId): void
    {
        if ($compteBancaireId !== null) {
            CompteBancaire::query()->findOrFail($compteBancaireId);
        }
    }

    /**
     * @return Collection<int, TransactionLigne>
     */
    private function verrouillerLotCanonique(int $ligneCanoniqueId): Collection
    {
        /** @var Collection<int, TransactionLigne> $lignes */
        $lignes = TransactionLigne::query()
            ->with(['compte', 'transaction'])
            ->withTrashed()
            ->where(function (Builder $query) use ($ligneCanoniqueId): void {
                $query
                    ->whereKey($ligneCanoniqueId)
                    ->orWhere('poste_tiers_parent_id', $ligneCanoniqueId);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lignes->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(TransactionLigne::class, [$ligneCanoniqueId]);
        }

        return $lignes;
    }

    private function assertDateDansExercice(CarbonImmutable $date, int $exercice): void
    {
        $periode = $this->exerciceService->dateRange($exercice);

        if ($date->startOfDay()->lt($periode['start']) || $date->startOfDay()->gt($periode['end'])) {
            throw new InvalidArgumentException(
                "La date du règlement doit appartenir à l'exercice {$this->exerciceService->label($exercice)}."
            );
        }
    }

    private function assertMontant(int $montantCentimes, int $soldeCentimes): void
    {
        if ($montantCentimes <= 0) {
            throw new InvalidArgumentException('Le montant du règlement doit être strictement positif.');
        }

        if ($montantCentimes > $soldeCentimes) {
            throw new InvalidArgumentException('Le montant du règlement ne peut pas dépasser le solde restant.');
        }
    }

    private function preparerPartPayee(
        PosteTiersOuvert $poste,
        int $montantPayeCentimes,
        Collection $lignesVerrouillees,
    ): TransactionLigne {
        $ligneIds = array_values(array_unique($poste->ligneIdsOuvertes));
        if ($ligneIds === []) {
            throw (new ModelNotFoundException)->setModel(TransactionLigne::class, [$poste->ligneActionId]);
        }

        /** @var Collection<int, TransactionLigne> $lignesOuvertes */
        $lignesOuvertes = $lignesVerrouillees
            ->whereIn('id', $ligneIds)
            ->sortBy('id')
            ->values();

        if ($lignesOuvertes->count() !== count($ligneIds)) {
            throw (new ModelNotFoundException)->setModel(TransactionLigne::class, $ligneIds);
        }

        $canonique = $lignesOuvertes->first(
            fn (TransactionLigne $ligne): bool => (int) $ligne->id === $poste->ligneActionId
        );
        if ($canonique === null) {
            throw (new ModelNotFoundException)->setModel(TransactionLigne::class, [$poste->ligneActionId]);
        }

        $this->assertFractionsCoherentes($canonique, $lignesOuvertes);

        $montantOuvert = $lignesOuvertes
            ->sum(fn (TransactionLigne $ligne): int => $this->montantCentimes($ligne));
        $this->assertMontant($montantPayeCentimes, $montantOuvert);

        if ($lignesOuvertes->count() > 1) {
            $canonique->update($this->montantsSelonSens($canonique, $montantOuvert));

            $lignesOuvertes
                ->reject(fn (TransactionLigne $ligne): bool => (int) $ligne->id === (int) $canonique->id)
                ->each(static fn (TransactionLigne $ligne): bool => $ligne->delete());
        }

        if ($montantPayeCentimes === $montantOuvert) {
            return $canonique->fresh(['compte', 'transaction']);
        }

        $reliquat = $montantOuvert - $montantPayeCentimes;
        $canonique->update($this->montantsSelonSens($canonique, $reliquat));

        $fraction = $canonique->replicate([
            'id',
            'lettrage_code',
            'deleted_at',
        ]);
        $fraction->fill($this->montantsSelonSens($canonique, $montantPayeCentimes));
        $fraction->poste_tiers_parent_id = (int) ($canonique->poste_tiers_parent_id ?? $canonique->id);
        $fraction->save();

        return $fraction->fresh(['compte', 'transaction']);
    }

    /**
     * @param  Collection<int, TransactionLigne>  $lignes
     */
    private function assertFractionsCoherentes(TransactionLigne $canonique, Collection $lignes): void
    {
        $canoniqueAuDebit = MontantDecimal::versCentimes((string) $canonique->debit) > 0;
        $ligneCanoniqueId = (int) ($canonique->poste_tiers_parent_id ?? $canonique->id);

        $coherentes = $lignes->every(function (TransactionLigne $ligne) use (
            $canonique,
            $canoniqueAuDebit,
            $ligneCanoniqueId,
        ): bool {
            $ligneAuDebit = MontantDecimal::versCentimes((string) $ligne->debit) > 0;
            $racineLigneId = (int) ($ligne->poste_tiers_parent_id ?? $ligne->id);

            return (int) $ligne->transaction_id === (int) $canonique->transaction_id
                && (int) $ligne->compte_id === (int) $canonique->compte_id
                && (int) $ligne->tiers_id === (int) $canonique->tiers_id
                && $racineLigneId === $ligneCanoniqueId
                && $ligneAuDebit === $canoniqueAuDebit;
        });

        if (! $coherentes) {
            throw new RuntimeException('Les fractions ouvertes du poste tiers sont incohérentes.');
        }
    }

    private function resoudreCompteTresorerie(
        TransactionLigne $ligneALettrer,
        ModePaiement $mode,
        ?int $compteBancaireId,
    ): Compte {
        $sens = MontantDecimal::versCentimes((string) $ligneALettrer->debit) > 0
            ? Sens::Recette
            : Sens::Depense;
        $compte = CompteTresorerieResolver::resoudre(
            compteBancaireId: $compteBancaireId,
            mode: $mode,
            contextLog: self::class.'::regler',
            sens: $sens,
        );

        if ($compte === null) {
            throw new RuntimeException(
                'Aucun compte de trésorerie valide n’a été trouvé pour ce règlement.'
            );
        }

        return $compte;
    }

    private function montantCentimes(TransactionLigne $ligne): int
    {
        return abs(
            MontantDecimal::versCentimes((string) $ligne->debit)
            - MontantDecimal::versCentimes((string) $ligne->credit)
        );
    }

    private function memesTiers(TransactionLigne $premiere, TransactionLigne $seconde): bool
    {
        if ($premiere->tiers_id === null || $seconde->tiers_id === null) {
            return $premiere->tiers_id === null && $seconde->tiers_id === null;
        }

        return (int) $premiere->tiers_id === (int) $seconde->tiers_id;
    }

    /**
     * @return array{debit: string, credit: string}
     */
    private function montantsSelonSens(TransactionLigne $ligne, int $montantCentimes): array
    {
        $montant = MontantDecimal::depuisCentimes($montantCentimes);

        return MontantDecimal::versCentimes((string) $ligne->debit) > 0
            ? ['debit' => $montant, 'credit' => '0.00']
            : ['debit' => '0.00', 'credit' => $montant];
    }
}
