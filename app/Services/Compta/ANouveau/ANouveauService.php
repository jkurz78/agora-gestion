<?php

declare(strict_types=1);

namespace App\Services\Compta\ANouveau;

use App\Enums\JournalComptable;
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\ANouveauInvalideException;
use App\Models\ANouveauGeneration;
use App\Models\ANouveauLigneOrigine;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\NumeroPieceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class ANouveauService
{
    public function __construct(private readonly NumeroPieceService $numeroPieceService) {}

    public function persister(
        ANouveauPreview $preview,
        OrigineANouveau $origine,
        User $acteur,
    ): ANouveauGeneration {
        $this->verifierActeur($acteur);
        $this->verifierPreview($preview);

        return DB::transaction(function () use ($preview, $origine, $acteur): ANouveauGeneration {
            $this->verrouillerTenant();

            Exercice::query()
                ->where('annee', $preview->exerciceSource)
                ->lockForUpdate()
                ->firstOr(function (): never {
                    throw new ANouveauInvalideException('L’exercice source des à-nouveaux est introuvable.');
                });

            $existante = ANouveauGeneration::query()
                ->where('exercice_cible', $preview->exerciceCible)
                ->where('statut', StatutANouveau::Active)
                ->lockForUpdate()
                ->first();

            if ($existante !== null) {
                return $existante;
            }

            $transaction = Transaction::create([
                'type' => TypeTransaction::AN,
                'date' => $preview->dateCible,
                'libelle' => "À-nouveaux de l’exercice {$preview->exerciceSource}",
                'montant_total' => $preview->totalDebit,
                'mode_paiement' => null,
                'reference' => "AN {$preview->exerciceSource}/{$preview->exerciceCible}",
                'notes' => 'Génération automatique des soldes d’ouverture',
                'saisi_par' => $acteur->id,
                'numero_piece' => $this->numeroPieceService->assign(Carbon::instance($preview->dateCible)),
                'equilibree' => false,
                'type_ecriture' => 'an',
                'journal' => JournalComptable::AN,
            ]);

            $generation = ANouveauGeneration::create([
                'exercice_source' => $preview->exerciceSource,
                'exercice_cible' => $preview->exerciceCible,
                'transaction_id' => $transaction->id,
                'origine' => $origine,
                'statut' => StatutANouveau::Active,
                'cree_par_id' => $acteur->id,
            ]);

            foreach ($preview->lignes as $lignePreview) {
                $compte = Compte::query()->find($lignePreview['compte_id']);
                if ($compte === null || $compte->numero_pcg !== $lignePreview['numero_pcg']) {
                    throw new ANouveauInvalideException(
                        "Le compte {$lignePreview['numero_pcg']} de l’aperçu est invalide."
                    );
                }

                $ligne = TransactionLigne::create([
                    'transaction_id' => $transaction->id,
                    'operation_id' => null,
                    'seance' => null,
                    'montant' => bccomp($lignePreview['debit'], '0.00', 2) > 0
                        ? $lignePreview['debit']
                        : $lignePreview['credit'],
                    'compte_id' => $compte->id,
                    'debit' => $lignePreview['debit'],
                    'credit' => $lignePreview['credit'],
                    'tiers_id' => $lignePreview['tiers_id'],
                    'lettrage_code' => null,
                    'libelle' => $lignePreview['libelle'],
                ]);

                $this->persisterOrigine($generation, $ligne, $lignePreview);
            }

            [$totalDebit, $totalCredit] = $this->totauxTransaction($transaction);
            if (bccomp($totalDebit, $totalCredit, 2) !== 0
                || bccomp($totalDebit, $preview->totalDebit, 2) !== 0
                || bccomp($totalCredit, $preview->totalCredit, 2) !== 0) {
                throw new ANouveauInvalideException('La pièce AN persistée est déséquilibrée.');
            }

            $transaction->update(['equilibree' => true]);

            return $generation->fresh(['transaction', 'origines']) ?? $generation;
        });
    }

    public function invalider(Exercice $source, User $acteur, string $motif): void
    {
        $this->verifierActeur($acteur);

        if (trim($motif) === '') {
            throw new ANouveauInvalideException('Le motif d’invalidation des à-nouveaux est obligatoire.');
        }

        DB::transaction(function () use ($source, $acteur, $motif): void {
            $this->verrouillerTenant();

            Exercice::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            $generation = ANouveauGeneration::query()
                ->where('exercice_source', (int) $source->annee)
                ->where('statut', StatutANouveau::Active)
                ->lockForUpdate()
                ->first();

            if ($generation === null) {
                return;
            }

            $transaction = $generation->transaction()->lockForUpdate()->first();
            if ($transaction !== null) {
                $transaction->lignes()->get()->each(
                    static fn (TransactionLigne $ligne): bool => $ligne->delete()
                );
                $transaction->delete();
            }

            $generation->update([
                'statut' => StatutANouveau::Invalidee,
                'invalidee_par_id' => $acteur->id,
                'invalidee_at' => now(),
                'motif_invalidation' => trim($motif),
            ]);
        });
    }

    private function verifierActeur(User $acteur): void
    {
        $associationId = TenantContext::currentId();
        if ($associationId === null || ! $acteur->associations()->whereKey($associationId)->exists()) {
            throw new ANouveauInvalideException('L’acteur ne peut pas générer d’à-nouveaux pour cette association.');
        }
    }

    private function verifierPreview(ANouveauPreview $preview): void
    {
        if ($preview->exerciceCible !== $preview->exerciceSource + 1) {
            throw new ANouveauInvalideException('L’exercice cible des à-nouveaux est invalide.');
        }

        $totalDebit = '0.00';
        $totalCredit = '0.00';
        foreach ($preview->lignes as $ligne) {
            if (bccomp($ligne['debit'], '0.00', 2) < 0 || bccomp($ligne['credit'], '0.00', 2) < 0) {
                throw new ANouveauInvalideException('Une ligne AN ne peut pas contenir un montant négatif.');
            }
            if ((bccomp($ligne['debit'], '0.00', 2) > 0) === (bccomp($ligne['credit'], '0.00', 2) > 0)) {
                throw new ANouveauInvalideException('Chaque ligne AN doit porter soit un débit, soit un crédit.');
            }

            $totalDebit = bcadd($totalDebit, $ligne['debit'], 2);
            $totalCredit = bcadd($totalCredit, $ligne['credit'], 2);
        }

        if (! $preview->equilibree()
            || bccomp($totalDebit, $totalCredit, 2) !== 0
            || bccomp($totalDebit, $preview->totalDebit, 2) !== 0
            || bccomp($totalCredit, $preview->totalCredit, 2) !== 0) {
            throw new ANouveauInvalideException('L’aperçu des à-nouveaux est déséquilibré.');
        }
    }

    private function verrouillerTenant(): void
    {
        DB::table('association')
            ->where('id', TenantContext::currentId())
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array{source_ligne_id: ?int, racine_ligne_id: ?int}  $lignePreview
     */
    private function persisterOrigine(
        ANouveauGeneration $generation,
        TransactionLigne $ligneAN,
        array $lignePreview,
    ): void {
        if ($lignePreview['source_ligne_id'] === null) {
            return;
        }

        $source = TransactionLigne::query()->find($lignePreview['source_ligne_id']);
        $racine = TransactionLigne::query()->find($lignePreview['racine_ligne_id']);
        if ($source === null || $racine === null) {
            throw new ANouveauInvalideException('La filiation d’un poste auxiliaire est invalide.');
        }

        ANouveauLigneOrigine::create([
            'generation_id' => $generation->id,
            'ligne_an_id' => $ligneAN->id,
            'ligne_source_id' => $source->id,
            'ligne_racine_id' => $racine->id,
        ]);
    }

    /** @return array{string, string} */
    private function totauxTransaction(Transaction $transaction): array
    {
        $debit = '0.00';
        $credit = '0.00';

        foreach ($transaction->lignes()->get() as $ligne) {
            $debit = bcadd($debit, (string) $ligne->debit, 2);
            $credit = bcadd($credit, (string) $ligne->credit, 2);
        }

        return [$debit, $credit];
    }
}
