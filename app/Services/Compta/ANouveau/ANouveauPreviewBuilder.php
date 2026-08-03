<?php

declare(strict_types=1);

namespace App\Services\Compta\ANouveau;

use App\Exceptions\Compta\ANouveauInvalideException;
use App\Models\ANouveauLigneOrigine;
use App\Models\Compte;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ANouveauPreviewBuilder
{
    public function __construct(private readonly ExerciceService $exerciceService) {}

    public function build(int $exerciceSource): ANouveauPreview
    {
        $range = $this->exerciceService->dateRange($exerciceSource);

        /** @var array<int, array{compte: Compte, solde: string}> $soldes */
        $soldes = [];
        /** @var list<array{compte_id: int, numero_pcg: string, debit: string, credit: string, tiers_id: ?int, libelle: string, source_ligne_id: ?int, racine_ligne_id: ?int}> $auxiliaires */
        $auxiliaires = [];
        $resultat = '0.00';

        TransactionLigne::query()
            ->with([
                'compte:id,association_id,numero_pcg,intitule,classe',
                'transaction:id,association_id,date,libelle',
            ])
            ->whereHas('transaction', function (Builder $query) use ($range): void {
                $query
                    ->whereDate('date', '>=', $range['start']->toDateString())
                    ->whereDate('date', '<=', $range['end']->toDateString());
            })
            ->whereHas('compte', fn (Builder $query) => $query->whereBetween('classe', [1, 7]))
            ->orderBy('transaction_lignes.id')
            ->chunkById(500, function (Collection $lignes) use (&$soldes, &$auxiliaires, &$resultat): void {
                /** @var TransactionLigne $ligne */
                foreach ($lignes as $ligne) {
                    $compte = $ligne->compte;
                    if ($compte === null) {
                        continue;
                    }

                    $debit = (string) $ligne->debit;
                    $credit = (string) $ligne->credit;

                    if ($compte->classe >= 6) {
                        $resultat = bcadd($resultat, bcsub($credit, $debit, 2), 2);

                        continue;
                    }

                    if (in_array($compte->numero_pcg, ['401', '411'], true)) {
                        if ($ligne->lettrage_code !== null) {
                            continue;
                        }

                        if ($ligne->tiers_id === null) {
                            throw new ANouveauInvalideException(
                                "Le poste {$compte->numero_pcg} de la ligne {$ligne->id} est sans tiers."
                            );
                        }

                        $solde = bcsub($debit, $credit, 2);
                        if (bccomp($solde, '0.00', 2) === 0) {
                            continue;
                        }

                        $auxiliaires[] = $this->ligneDepuisSolde(
                            compte: $compte,
                            solde: $solde,
                            tiersId: (int) $ligne->tiers_id,
                            libelle: $ligne->libelle ?: ($ligne->transaction?->libelle ?: 'Poste reporté'),
                            sourceLigneId: (int) $ligne->id,
                            racineLigneId: (int) $ligne->id,
                        );

                        continue;
                    }

                    $compteId = (int) $compte->id;
                    if (! isset($soldes[$compteId])) {
                        $soldes[$compteId] = ['compte' => $compte, 'solde' => '0.00'];
                    }
                    $soldes[$compteId]['solde'] = bcadd(
                        $soldes[$compteId]['solde'],
                        bcsub($debit, $credit, 2),
                        2,
                    );
                }
            }, 'transaction_lignes.id', 'id');

        $this->resoudreRacines($auxiliaires);
        $this->ajouterResultat($soldes, $resultat);

        $lignes = $auxiliaires;
        foreach ($soldes as $soldeCompte) {
            if (bccomp($soldeCompte['solde'], '0.00', 2) === 0) {
                continue;
            }

            $lignes[] = $this->ligneDepuisSolde(
                compte: $soldeCompte['compte'],
                solde: $soldeCompte['solde'],
                tiersId: null,
                libelle: 'À-nouveau '.$soldeCompte['compte']->numero_pcg.' — '.$soldeCompte['compte']->intitule,
                sourceLigneId: null,
                racineLigneId: null,
            );
        }

        usort($lignes, static function (array $a, array $b): int {
            return [$a['numero_pcg'], $a['source_ligne_id'] ?? 0]
                <=> [$b['numero_pcg'], $b['source_ligne_id'] ?? 0];
        });

        $totalDebit = '0.00';
        $totalCredit = '0.00';
        foreach ($lignes as $ligne) {
            $totalDebit = bcadd($totalDebit, $ligne['debit'], 2);
            $totalCredit = bcadd($totalCredit, $ligne['credit'], 2);
        }

        $exerciceCible = $exerciceSource + 1;
        $dateCible = CarbonImmutable::instance($this->exerciceService->dateRange($exerciceCible)['start']);

        return new ANouveauPreview(
            exerciceSource: $exerciceSource,
            exerciceCible: $exerciceCible,
            dateCible: $dateCible,
            lignes: $lignes,
            totalDebit: $totalDebit,
            totalCredit: $totalCredit,
        );
    }

    /**
     * @param  array<int, array{compte: Compte, solde: string}>  $soldes
     */
    private function ajouterResultat(array &$soldes, string $resultat): void
    {
        if (bccomp($resultat, '0.00', 2) === 0) {
            return;
        }

        $numero = bccomp($resultat, '0.00', 2) > 0 ? '120' : '129';
        $compte = Compte::ofNumeroSysteme($numero);
        $compteId = (int) $compte->id;
        if (! isset($soldes[$compteId])) {
            $soldes[$compteId] = ['compte' => $compte, 'solde' => '0.00'];
        }

        $soldeResultat = bcmul($resultat, '-1', 2);
        $soldes[$compteId]['solde'] = bcadd($soldes[$compteId]['solde'], $soldeResultat, 2);
    }

    /**
     * @param  list<array{compte_id: int, numero_pcg: string, debit: string, credit: string, tiers_id: ?int, libelle: string, source_ligne_id: ?int, racine_ligne_id: ?int}>  $auxiliaires
     */
    private function resoudreRacines(array &$auxiliaires): void
    {
        $ids = collect($auxiliaires)
            ->pluck('source_ligne_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return;
        }

        $racines = ANouveauLigneOrigine::query()
            ->whereIn('ligne_an_id', $ids)
            ->pluck('ligne_racine_id', 'ligne_an_id')
            ->mapWithKeys(fn (mixed $racine, mixed $ligne): array => [(int) $ligne => (int) $racine])
            ->all();

        foreach ($auxiliaires as &$ligne) {
            $sourceId = $ligne['source_ligne_id'];
            if ($sourceId !== null && isset($racines[$sourceId])) {
                $ligne['racine_ligne_id'] = $racines[$sourceId];
            }
        }
        unset($ligne);
    }

    /**
     * @return array{compte_id: int, numero_pcg: string, debit: string, credit: string, tiers_id: ?int, libelle: string, source_ligne_id: ?int, racine_ligne_id: ?int}
     */
    private function ligneDepuisSolde(
        Compte $compte,
        string $solde,
        ?int $tiersId,
        string $libelle,
        ?int $sourceLigneId,
        ?int $racineLigneId,
    ): array {
        $debiteur = bccomp($solde, '0.00', 2) > 0;

        return [
            'compte_id' => (int) $compte->id,
            'numero_pcg' => (string) $compte->numero_pcg,
            'debit' => $debiteur ? $solde : '0.00',
            'credit' => $debiteur ? '0.00' : bcmul($solde, '-1', 2),
            'tiers_id' => $tiersId,
            'libelle' => $libelle,
            'source_ligne_id' => $sourceLigneId,
            'racine_ligne_id' => $racineLigneId,
        ];
    }
}
