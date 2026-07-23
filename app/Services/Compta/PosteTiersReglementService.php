<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\DTOs\Compta\PosteTiersOuvert;
use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\Sens;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;
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
        return DB::transaction(function () use ($data): Transaction {
            $ligneDemandee = TransactionLigne::query()
                ->with(['compte', 'transaction'])
                ->lockForUpdate()
                ->findOrFail($data->ligneId);

            $this->exerciceService->assertOuvert($data->exercice);
            $this->assertDateDansExercice($data->date, $data->exercice);

            $poste = $this->postesOuverts->trouver((int) $ligneDemandee->id, $data->exercice);
            $this->assertMontant($data->montantCentimes, $poste->soldeCentimes);

            $ligneALettrer = $this->preparerPartPayee($poste, $data->montantCentimes);
            $compteTresorerie = $this->resoudreCompteTresorerie($ligneALettrer, $data);
            $t1 = Transaction::findOrFail($poste->transactionOrigineId);

            $t2 = $this->ecritureGenerator->pourReglement(
                t1: $t1,
                mode: $data->mode,
                compteTresorerie: $compteTresorerie,
                datePaiement: $data->date,
                ligneTiersSource: $ligneALettrer,
            );

            if ($data->compteBancaireId !== null
                && (int) $t2->compte_id !== $data->compteBancaireId) {
                $t2->update(['compte_id' => $data->compteBancaireId]);
            }

            $this->etatReglementResolver->syncer($t1->fresh());

            return $t2;
        }, 3);
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

    private function preparerPartPayee(PosteTiersOuvert $poste, int $montantPayeCentimes): TransactionLigne
    {
        $ligneIds = array_values(array_unique($poste->ligneIdsOuvertes));
        if ($ligneIds === []) {
            throw (new ModelNotFoundException)->setModel(TransactionLigne::class, [$poste->ligneActionId]);
        }

        /** @var Collection<int, TransactionLigne> $lignesOuvertes */
        $lignesOuvertes = TransactionLigne::query()
            ->with(['compte', 'transaction'])
            ->whereIn('id', $ligneIds)
            ->whereNull('lettrage_code')
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

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
        $canoniqueAuDebit = (int) round((float) $canonique->debit * 100) > 0;
        $ligneCanoniqueId = (int) ($canonique->poste_tiers_parent_id ?? $canonique->id);

        $coherentes = $lignes->every(function (TransactionLigne $ligne) use (
            $canonique,
            $canoniqueAuDebit,
            $ligneCanoniqueId,
        ): bool {
            $ligneAuDebit = (int) round((float) $ligne->debit * 100) > 0;
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
        PosteTiersReglementData $data,
    ): Compte {
        $sens = (int) round((float) $ligneALettrer->debit * 100) > 0
            ? Sens::Recette
            : Sens::Depense;
        $compte = CompteTresorerieResolver::resoudre(
            compteBancaireId: $data->compteBancaireId,
            mode: $data->mode,
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
            (int) round((float) $ligne->debit * 100)
            - (int) round((float) $ligne->credit * 100)
        );
    }

    /**
     * @return array{debit: string, credit: string}
     */
    private function montantsSelonSens(TransactionLigne $ligne, int $montantCentimes): array
    {
        $montant = $this->decimalDepuisCentimes($montantCentimes);

        return (int) round((float) $ligne->debit * 100) > 0
            ? ['debit' => $montant, 'credit' => '0.00']
            : ['debit' => '0.00', 'credit' => $montant];
    }

    private function decimalDepuisCentimes(int $montantCentimes): string
    {
        return intdiv($montantCentimes, 100).'.'.str_pad(
            (string) ($montantCentimes % 100),
            2,
            '0',
            STR_PAD_LEFT,
        );
    }
}
