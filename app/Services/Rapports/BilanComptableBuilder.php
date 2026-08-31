<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Enums\JournalComptable;
use App\Enums\StatutExercice;
use App\Models\Exercice;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use App\Support\MontantDecimal;
use Illuminate\Database\Eloquent\Builder;

final class BilanComptableBuilder
{
    public function __construct(
        private readonly BalanceComptableBuilder $balanceBuilder,
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $exercice, bool $compareN1 = true): array
    {
        $balanceN = $this->balanceExercice($exercice);
        $balanceN1 = $compareN1 ? $this->balanceExercice($exercice - 1) : [];
        $actifs = $this->actifs($balanceN, $balanceN1);
        $resultatCourant = [
            'n_centimes' => $this->resultatCourant($exercice),
            'n_1_centimes' => $compareN1 ? $this->resultatCourant($exercice - 1) : 0,
        ];
        $passifs = $this->passifs($balanceN, $balanceN1, $resultatCourant);

        $totaux = [
            'actif_n_brut_centimes' => array_sum(array_column($actifs, 'brut_n_centimes')),
            'actif_n_amortissements_provisions_centimes' => array_sum(array_column($actifs, 'amortissements_provisions_n_centimes')),
            'actif_n_net_centimes' => array_sum(array_column($actifs, 'net_n_centimes')),
            'actif_n_1_net_centimes' => array_sum(array_column($actifs, 'net_n_1_centimes')),
            'passif_n_centimes' => array_sum(array_column($passifs, 'montant_n_centimes')),
            'passif_n_1_centimes' => array_sum(array_column($passifs, 'montant_n_1_centimes')),
        ];
        $exerciceModele = Exercice::query()->where('annee', $exercice)->first();
        $provisoire = $exerciceModele?->statut !== StatutExercice::Cloture;

        return [
            'exercice' => $exercice,
            'label_n' => $this->exerciceService->label($exercice),
            'label_n_1' => $this->exerciceService->label($exercice - 1),
            'provisoire' => $provisoire,
            'statut' => $provisoire ? 'Bilan provisoire avant clôture' : 'Bilan clôturé',
            'actif' => $actifs,
            'passif' => $passifs,
            'resultat_courant' => $resultatCourant,
            'totaux' => $totaux,
            'ecart_actif_passif' => [
                'n_centimes' => $totaux['actif_n_net_centimes'] - $totaux['passif_n_centimes'],
                'n_1_centimes' => $totaux['actif_n_1_net_centimes'] - $totaux['passif_n_1_centimes'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function balanceExercice(int $exercice): array
    {
        $range = $this->exerciceService->dateRange($exercice);
        $balance = $this->balanceBuilder->balance(
            $range['start']->toDateString(),
            $range['end']->toDateString(),
            ['1', '2', '3', '4', '5'],
        );
        $lignes = [];

        foreach ($balance['lignes'] as $ligne) {
            $lignes[(string) $ligne['numero_compte']] = [
                'solde_fin_centimes' => (int) $ligne['solde_fin_centimes'],
                'solde_ouverture_centimes' => (int) $ligne['solde_ouverture_centimes'],
            ];
        }

        return $lignes;
    }

    /**
     * @param  array<string, array<string, int>>  $balanceN
     * @param  array<string, array<string, int>>  $balanceN1
     * @return list<array<string, int|string>>
     */
    private function actifs(array $balanceN, array $balanceN1): array
    {
        $rubriques = $this->rubriquesActifVides();

        $this->repartirActifs($rubriques, $balanceN, 'n');
        $this->repartirActifs($rubriques, $balanceN1, 'n_1');

        return collect($rubriques)
            ->map(function (array $rubrique): array {
                $brutN = (int) $rubrique['brut_n_centimes'];
                $amortissementsN = (int) $rubrique['amortissements_provisions_n_centimes'];
                $brutN1 = (int) $rubrique['brut_n_1_centimes'];
                $amortissementsN1 = (int) $rubrique['amortissements_provisions_n_1_centimes'];

                return [
                    'code' => (string) $rubrique['code'],
                    'libelle' => (string) $rubrique['libelle'],
                    'brut_n_centimes' => $brutN,
                    'amortissements_provisions_n_centimes' => $amortissementsN,
                    'net_n_centimes' => $brutN - $amortissementsN,
                    'net_n_1_centimes' => $brutN1 - $amortissementsN1,
                ];
            })
            ->filter(fn (array $rubrique): bool => (int) $rubrique['net_n_centimes'] !== 0
                || (int) $rubrique['net_n_1_centimes'] !== 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, int>>  $balanceN
     * @param  array<string, array<string, int>>  $balanceN1
     * @param  array{n_centimes: int, n_1_centimes: int}  $resultatCourant
     * @return list<array<string, int|string>>
     */
    private function passifs(array $balanceN, array $balanceN1, array $resultatCourant): array
    {
        $rubriques = $this->rubriquesPassifVides();

        $this->repartirPassifs($rubriques, $balanceN, 'n');
        $this->repartirPassifs($rubriques, $balanceN1, 'n_1');
        $rubriques['resultats_anterieurs']['montant_n_centimes'] = $this->resultatsAnterieurs($balanceN);
        $rubriques['resultats_anterieurs']['montant_n_1_centimes'] = $this->resultatsAnterieurs($balanceN1);
        $rubriques['resultat_courant']['montant_n_centimes'] = $resultatCourant['n_centimes'];
        $rubriques['resultat_courant']['montant_n_1_centimes'] = $resultatCourant['n_1_centimes'];

        return collect($rubriques)
            ->filter(fn (array $rubrique): bool => (int) $rubrique['montant_n_centimes'] !== 0
                || (int) $rubrique['montant_n_1_centimes'] !== 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function rubriquesActifVides(): array
    {
        return [
            'immobilisations_incorporelles' => $this->rubriqueActif('immobilisations_incorporelles', 'Immobilisations incorporelles'),
            'immobilisations_corporelles' => $this->rubriqueActif('immobilisations_corporelles', 'Immobilisations corporelles'),
            'immobilisations_financieres' => $this->rubriqueActif('immobilisations_financieres', 'Immobilisations financières'),
            'stocks' => $this->rubriqueActif('stocks', 'Stocks'),
            'creances_clients' => $this->rubriqueActif('creances_clients', 'Créances clients'),
            'autres_creances' => $this->rubriqueActif('autres_creances', 'Autres créances'),
            'charges_constatees_avance' => $this->rubriqueActif('charges_constatees_avance', 'Charges constatées d’avance'),
            'valeurs_mobilieres_placement' => $this->rubriqueActif('valeurs_mobilieres_placement', 'Valeurs mobilières de placement'),
            'disponibilites' => $this->rubriqueActif('disponibilites', 'Disponibilités'),
        ];
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function rubriquesPassifVides(): array
    {
        return [
            'fonds_propres' => $this->rubriquePassif('fonds_propres', 'Fonds propres'),
            'resultats_anterieurs' => $this->rubriquePassif('resultats_anterieurs', 'Résultats antérieurs'),
            'resultat_courant' => $this->rubriquePassif('resultat_courant', 'Résultat de l’exercice'),
            'provisions_risques_charges' => $this->rubriquePassif('provisions_risques_charges', 'Provisions pour risques et charges'),
            'emprunts_dettes_assimilees' => $this->rubriquePassif('emprunts_dettes_assimilees', 'Emprunts et dettes assimilées'),
            'dettes_fournisseurs' => $this->rubriquePassif('dettes_fournisseurs', 'Dettes fournisseurs'),
            'autres_dettes' => $this->rubriquePassif('autres_dettes', 'Autres dettes'),
            'produits_constates_avance' => $this->rubriquePassif('produits_constates_avance', 'Produits constatés d’avance'),
            'decouverts_bancaires' => $this->rubriquePassif('decouverts_bancaires', 'Découverts bancaires'),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function rubriqueActif(string $code, string $libelle): array
    {
        return [
            'code' => $code,
            'libelle' => $libelle,
            'brut_n_centimes' => 0,
            'amortissements_provisions_n_centimes' => 0,
            'brut_n_1_centimes' => 0,
            'amortissements_provisions_n_1_centimes' => 0,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function rubriquePassif(string $code, string $libelle): array
    {
        return [
            'code' => $code,
            'libelle' => $libelle,
            'montant_n_centimes' => 0,
            'montant_n_1_centimes' => 0,
        ];
    }

    /**
     * @param  array<string, array<string, int|string>>  $rubriques
     * @param  array<string, array<string, int>>  $balance
     */
    private function repartirActifs(array &$rubriques, array $balance, string $periode): void
    {
        foreach ($balance as $numero => $ligne) {
            $numero = (string) $numero;
            $solde = (int) $ligne['solde_fin_centimes'];
            $rubriqueImmobilisation = $this->rubriqueImmobilisation($numero);
            $rubriqueContreImmobilisation = $this->rubriqueContreImmobilisation($numero);

            if ($rubriqueImmobilisation !== null) {
                $this->ajouterActif($rubriques, $rubriqueImmobilisation, $periode, $solde);
            } elseif ($rubriqueContreImmobilisation !== null) {
                $this->ajouterActif($rubriques, $rubriqueContreImmobilisation, $periode, -$solde, true);
            } elseif (str_starts_with($numero, '39')) {
                $this->ajouterActif($rubriques, 'stocks', $periode, -$solde, true);
            } elseif (str_starts_with($numero, '3')) {
                $this->ajouterActif($rubriques, 'stocks', $periode, $solde);
            } elseif (str_starts_with($numero, '491')) {
                $this->ajouterActif($rubriques, 'creances_clients', $periode, -$solde, true);
            } elseif (str_starts_with($numero, '411') && $solde > 0) {
                $this->ajouterActif($rubriques, 'creances_clients', $periode, $solde);
            } elseif ($numero === '486' && $solde > 0) {
                $this->ajouterActif($rubriques, 'charges_constatees_avance', $periode, $solde);
            } elseif (str_starts_with($numero, '49')) {
                $this->ajouterActif($rubriques, 'autres_creances', $periode, -$solde, true);
            } elseif ((str_starts_with($numero, '401') || $numero === '487'
                || $this->estCompteEmprunt($numero)) && $solde > 0) {
                $this->ajouterActif($rubriques, 'autres_creances', $periode, $solde);
            } elseif ($this->estAutreCompteTiers($numero) && $solde > 0) {
                $this->ajouterActif($rubriques, 'autres_creances', $periode, $solde);
            } elseif (str_starts_with($numero, '59')) {
                $this->ajouterActif($rubriques, 'valeurs_mobilieres_placement', $periode, -$solde, true);
            } elseif (str_starts_with($numero, '50') && $solde > 0) {
                $this->ajouterActif($rubriques, 'valeurs_mobilieres_placement', $periode, $solde);
            } elseif (str_starts_with($numero, '5') && $solde > 0) {
                $this->ajouterActif($rubriques, 'disponibilites', $periode, $solde);
            }
        }
    }

    /**
     * @param  array<string, array<string, int|string>>  $rubriques
     */
    private function ajouterActif(array &$rubriques, string $code, string $periode, int $montant, bool $deduction = false): void
    {
        $cle = $deduction
            ? 'amortissements_provisions_'.$periode.'_centimes'
            : 'brut_'.$periode.'_centimes';
        $rubriques[$code][$cle] += $montant;
    }

    /**
     * @param  array<string, array<string, int|string>>  $rubriques
     * @param  array<string, array<string, int>>  $balance
     */
    private function repartirPassifs(array &$rubriques, array $balance, string $periode): void
    {
        foreach ($balance as $numero => $ligne) {
            $numero = (string) $numero;
            $solde = (int) $ligne['solde_fin_centimes'];
            $montant = -$solde;

            if (str_starts_with($numero, '15')) {
                $this->ajouterPassif($rubriques, 'provisions_risques_charges', $periode, $montant);
            } elseif (str_starts_with($numero, '10') || str_starts_with($numero, '11')
                || str_starts_with($numero, '13') || str_starts_with($numero, '14')) {
                $this->ajouterPassif($rubriques, 'fonds_propres', $periode, $montant);
            } elseif ($this->estCompteEmprunt($numero) && $solde < 0) {
                $this->ajouterPassif($rubriques, 'emprunts_dettes_assimilees', $periode, $montant);
            } elseif (str_starts_with($numero, '401') && $solde < 0) {
                $this->ajouterPassif($rubriques, 'dettes_fournisseurs', $periode, $montant);
            } elseif ($numero === '487' && $solde < 0) {
                $this->ajouterPassif($rubriques, 'produits_constates_avance', $periode, $montant);
            } elseif ((str_starts_with($numero, '411') || $numero === '486'
                || str_starts_with($numero, '50')) && $solde < 0) {
                $this->ajouterPassif($rubriques, 'autres_dettes', $periode, $montant);
            } elseif ($this->estAutreCompteTiers($numero) && $solde < 0) {
                $this->ajouterPassif($rubriques, 'autres_dettes', $periode, $montant);
            } elseif (str_starts_with($numero, '5') && ! str_starts_with($numero, '59') && $solde < 0) {
                // Les 59 sont des dépréciations : elles vivent déjà en déduction
                // de l'actif. Les reporter ici les compterait deux fois — même
                // exclusion que celle des 49 dans estAutreCompteTiers().
                $this->ajouterPassif($rubriques, 'decouverts_bancaires', $periode, $montant);
            }
        }
    }

    /**
     * @param  array<string, array<string, int|string>>  $rubriques
     */
    private function ajouterPassif(array &$rubriques, string $code, string $periode, int $montant): void
    {
        $rubriques[$code]['montant_'.$periode.'_centimes'] += $montant;
    }

    private function rubriqueImmobilisation(string $numero): ?string
    {
        if (str_starts_with($numero, '20')) {
            return 'immobilisations_incorporelles';
        }

        if (str_starts_with($numero, '21') || str_starts_with($numero, '22') || str_starts_with($numero, '23')) {
            return 'immobilisations_corporelles';
        }

        if (str_starts_with($numero, '24') || str_starts_with($numero, '25')
            || str_starts_with($numero, '26') || str_starts_with($numero, '27')) {
            return 'immobilisations_financieres';
        }

        return null;
    }

    private function rubriqueContreImmobilisation(string $numero): ?string
    {
        if (! str_starts_with($numero, '28') && ! str_starts_with($numero, '29')) {
            return null;
        }

        return match ($numero[2] ?? '') {
            '0' => 'immobilisations_incorporelles',
            '1', '2', '3' => 'immobilisations_corporelles',
            default => 'immobilisations_financieres',
        };
    }

    private function estAutreCompteTiers(string $numero): bool
    {
        return str_starts_with($numero, '4')
            && ! str_starts_with($numero, '401')
            && ! str_starts_with($numero, '411')
            && ! str_starts_with($numero, '49')
            && $numero !== '486'
            && $numero !== '487';
    }

    private function estCompteEmprunt(string $numero): bool
    {
        return str_starts_with($numero, '16')
            || str_starts_with($numero, '17')
            || str_starts_with($numero, '18');
    }

    /**
     * @param  array<string, array<string, int>>  $balance
     */
    private function resultatsAnterieurs(array $balance): int
    {
        $soldeOuverture = 0;

        foreach ($balance as $numero => $ligne) {
            $numero = (string) $numero;
            if ($numero === '120' || $numero === '129') {
                $soldeOuverture += (int) $ligne['solde_ouverture_centimes'];
            }
        }

        return -$soldeOuverture;
    }

    private function resultatCourant(int $exercice): int
    {
        $range = $this->exerciceService->dateRange($exercice);

        return TransactionLigne::query()
            ->whereHas('transaction', function (Builder $query) use ($range): void {
                $query->whereDate('date', '>=', $range['start']->toDateString())
                    ->whereDate('date', '<=', $range['end']->toDateString())
                    ->where('journal', '!=', JournalComptable::AN->value);
            })
            ->whereHas('compte', fn (Builder $query): Builder => $query->whereIn('classe', [6, 7]))
            ->get(['debit', 'credit'])
            ->sum(fn (TransactionLigne $ligne): int => MontantDecimal::versCentimes((string) $ligne->credit)
                - MontantDecimal::versCentimes((string) $ligne->debit));
    }
}
