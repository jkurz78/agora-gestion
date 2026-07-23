<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\DTOs\Compta\PosteTiersOuvert;
use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use App\Support\MontantDecimal;
use Carbon\CarbonImmutable;
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

    private function executer(
        int $ligneId,
        ?int $montantCentimes,
        CarbonImmutable $date,
        ModePaiement $mode,
        ?int $compteBancaireId,
        int $exercice,
    ): Transaction {
        // Résolution sans verrou avant l'ouverture de la transaction : on ne
        // verrouille jamais une fraction cible avant sa racine, et cette
        // lecture ne fige pas un snapshot InnoDB qui survivrait à un retry.
        $ligneReference = TransactionLigne::query()
            ->withTrashed()
            ->findOrFail($ligneId);
        $ligneCanoniqueId = (int) ($ligneReference->poste_tiers_parent_id ?? $ligneReference->id);

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
            $this->assertCompteBancaireDuTenant($compteBancaireId);

            $lignesVerrouillees = $this->verrouillerLotCanonique($ligneCanoniqueId);

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
        }, 3);
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
