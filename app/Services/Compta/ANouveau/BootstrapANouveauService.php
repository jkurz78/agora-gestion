<?php

declare(strict_types=1);

namespace App\Services\Compta\ANouveau;

use App\Exceptions\Compta\ANouveauInvalideException;
use App\Models\ANouveauGeneration;
use App\Models\ANouveauLigneOrigine;
use App\Models\Compte;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class BootstrapANouveauService
{
    /** @var list<array{numero_pcg: string, solde_initial: string, date_reference: ?string, mouvement_reference: string, montant_propose: string}> */
    private array $detailsBancaires = [];

    public function __construct(private readonly ExerciceService $exerciceService) {}

    /**
     * @param  array{meme_jour?: 'inclus'|'exclus'}  $arbitrages
     */
    public function preview(int $exerciceCible, array $arbitrages = []): ANouveauPreview
    {
        if (ANouveauGeneration::activePourCible($exerciceCible) !== null) {
            throw new ANouveauInvalideException(
                "Une génération d’à-nouveaux active existe déjà pour l’exercice {$exerciceCible}."
            );
        }

        $modeMemeJour = $arbitrages['meme_jour'] ?? null;
        if ($modeMemeJour !== null && ! in_array($modeMemeJour, ['inclus', 'exclus'], true)) {
            throw new ANouveauInvalideException('L’arbitrage même-jour doit valoir inclus ou exclus.');
        }

        $dateOuverture = CarbonImmutable::instance(
            $this->exerciceService->dateRange($exerciceCible)['start']
        );

        /** @var array<int, array{compte: Compte, solde: string}> $soldes */
        $soldes = [];
        /** @var list<array{compte_id: int, numero_pcg: string, debit: string, credit: string, tiers_id: ?int, libelle: string, source_ligne_id: ?int, racine_ligne_id: ?int}> $auxiliaires */
        $auxiliaires = [];

        TransactionLigne::query()
            ->with([
                'compte:id,association_id,numero_pcg,intitule,classe,compte_bancaire_id',
                'transaction:id,association_id,date,libelle',
            ])
            ->whereHas('transaction', fn (Builder $query) => $query
                ->whereDate('date', '<', $dateOuverture->toDateString()))
            ->whereHas('compte', fn (Builder $query) => $query->whereBetween('classe', [1, 5]))
            ->orderBy('transaction_lignes.id')
            ->chunkById(500, function (Collection $lignes) use (&$soldes, &$auxiliaires): void {
                /** @var TransactionLigne $ligne */
                foreach ($lignes as $ligne) {
                    $compte = $ligne->compte;
                    if ($compte === null) {
                        continue;
                    }

                    $solde = bcsub((string) $ligne->debit, (string) $ligne->credit, 2);
                    if (in_array($compte->numero_pcg, ['401', '411'], true)) {
                        if ($ligne->lettrage_code !== null || bccomp($solde, '0.00', 2) === 0) {
                            continue;
                        }
                        if ($ligne->tiers_id === null) {
                            throw new ANouveauInvalideException(
                                "Le poste {$compte->numero_pcg} de la ligne {$ligne->id} est sans tiers."
                            );
                        }

                        $racine = ANouveauLigneOrigine::query()
                            ->where('ligne_an_id', $ligne->id)
                            ->latest('generation_id')
                            ->value('ligne_racine_id');
                        $auxiliaires[] = $this->ligneDepuisSolde(
                            $compte,
                            $solde,
                            (int) $ligne->tiers_id,
                            $ligne->libelle ?: ($ligne->transaction?->libelle ?: 'Poste repris'),
                            (int) $ligne->id,
                            $racine !== null ? (int) $racine : (int) $ligne->id,
                        );

                        continue;
                    }

                    $this->ajouterSolde($soldes, $compte, $solde);
                }
            }, 'transaction_lignes.id', 'id');

        $this->appliquerSoldesBancaires($soldes, $dateOuverture, $modeMemeJour);
        $this->equilibrerSur102($soldes, $auxiliaires);

        $lignes = $auxiliaires;
        foreach ($soldes as $soldeCompte) {
            if (bccomp($soldeCompte['solde'], '0.00', 2) !== 0) {
                $lignes[] = $this->ligneDepuisSolde(
                    $soldeCompte['compte'],
                    $soldeCompte['solde'],
                    null,
                    'Reprise initiale '.$soldeCompte['compte']->numero_pcg.' — '.$soldeCompte['compte']->intitule,
                    null,
                    null,
                );
            }
        }

        usort($lignes, static fn (array $a, array $b): int => [$a['numero_pcg'], $a['source_ligne_id'] ?? 0]
            <=> [$b['numero_pcg'], $b['source_ligne_id'] ?? 0]);

        $totalDebit = '0.00';
        $totalCredit = '0.00';
        foreach ($lignes as $ligne) {
            $totalDebit = bcadd($totalDebit, $ligne['debit'], 2);
            $totalCredit = bcadd($totalCredit, $ligne['credit'], 2);
        }

        return new ANouveauPreview(
            exerciceSource: $exerciceCible - 1,
            exerciceCible: $exerciceCible,
            dateCible: $dateOuverture,
            lignes: $lignes,
            totalDebit: $totalDebit,
            totalCredit: $totalCredit,
        );
    }

    /** @return list<array{numero_pcg: string, solde_initial: string, date_reference: ?string, mouvement_reference: string, montant_propose: string}> */
    public function detailsBancaires(): array
    {
        return $this->detailsBancaires;
    }

    /**
     * @param  array<int, array{compte: Compte, solde: string}>  $soldes
     */
    private function appliquerSoldesBancaires(
        array &$soldes,
        CarbonImmutable $dateOuverture,
        ?string $modeMemeJour,
    ): void {
        $this->detailsBancaires = [];
        $comptes = Compte::query()->with('compteBancaire')->bancaires()->get();

        foreach ($comptes as $compte) {
            $banque = $compte->compteBancaire;
            if ($banque === null || $banque->solde_initial === null) {
                continue;
            }

            $dateReference = $banque->date_solde_initial?->toDateString();
            $mouvementMemeJour = '0.00';
            if ($dateReference !== null) {
                $mouvementMemeJour = $this->mouvementCompte($compte, $dateReference, $dateReference);
                if (bccomp($mouvementMemeJour, '0.00', 2) !== 0 && $modeMemeJour === null) {
                    throw new ANouveauInvalideException(
                        "Le compte {$compte->numero_pcg} a des mouvements le {$dateReference}. "
                        .'Relancez avec --meme-jour=inclus|exclus.'
                    );
                }
            }

            $mouvements = '0.00';
            if ($dateReference !== null && $dateReference < $dateOuverture->toDateString()) {
                $dateDebut = $modeMemeJour === 'inclus'
                    ? $dateReference
                    : CarbonImmutable::parse($dateReference)->addDay()->toDateString();
                $mouvements = $this->mouvementCompte(
                    $compte,
                    $dateDebut,
                    $dateOuverture->subDay()->toDateString(),
                );
            }

            $propose = bcadd((string) $banque->solde_initial, $mouvements, 2);
            $soldes[(int) $compte->id] = ['compte' => $compte, 'solde' => $propose];
            $this->detailsBancaires[] = [
                'numero_pcg' => (string) $compte->numero_pcg,
                'solde_initial' => (string) $banque->solde_initial,
                'date_reference' => $dateReference,
                'mouvement_reference' => $mouvementMemeJour,
                'montant_propose' => $propose,
            ];
        }
    }

    private function mouvementCompte(Compte $compte, string $dateDebut, string $dateFin): string
    {
        $debit = '0.00';
        $credit = '0.00';
        TransactionLigne::query()
            ->where('compte_id', $compte->id)
            ->whereHas('transaction', fn (Builder $query) => $query
                ->whereDate('date', '>=', $dateDebut)
                ->whereDate('date', '<=', $dateFin))
            ->orderBy('id')
            ->chunkById(500, function (Collection $lignes) use (&$debit, &$credit): void {
                foreach ($lignes as $ligne) {
                    $debit = bcadd($debit, (string) $ligne->debit, 2);
                    $credit = bcadd($credit, (string) $ligne->credit, 2);
                }
            });

        return bcsub($debit, $credit, 2);
    }

    /**
     * @param  array<int, array{compte: Compte, solde: string}>  $soldes
     * @param  list<array{debit: string, credit: string}>  $auxiliaires
     */
    private function equilibrerSur102(array &$soldes, array $auxiliaires): void
    {
        $net = '0.00';
        foreach ($soldes as $soldeCompte) {
            $net = bcadd($net, $soldeCompte['solde'], 2);
        }
        foreach ($auxiliaires as $ligne) {
            $net = bcadd($net, bcsub($ligne['debit'], $ligne['credit'], 2), 2);
        }

        if (bccomp($net, '0.00', 2) === 0) {
            return;
        }

        $compte102 = Compte::ofNumeroSysteme('102');
        $this->ajouterSolde($soldes, $compte102, bcmul($net, '-1', 2));
    }

    /** @param array<int, array{compte: Compte, solde: string}> $soldes */
    private function ajouterSolde(array &$soldes, Compte $compte, string $montant): void
    {
        $id = (int) $compte->id;
        if (! isset($soldes[$id])) {
            $soldes[$id] = ['compte' => $compte, 'solde' => '0.00'];
        }
        $soldes[$id]['solde'] = bcadd($soldes[$id]['solde'], $montant, 2);
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
