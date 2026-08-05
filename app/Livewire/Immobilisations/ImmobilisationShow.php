<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\PlanAmortissementCalculator;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Component;

final class ImmobilisationShow extends Component
{
    public Immobilisation $immobilisation;

    public function mount(Immobilisation $immobilisation): void
    {
        $this->immobilisation = $immobilisation;
    }

    public function render(): View
    {
        $this->immobilisation->load(['compte', 'compteAmortissement', 'dotations.transaction', 'transaction.tiers']);

        return view('livewire.immobilisations.immobilisation-show', [
            'plan' => $this->construirePlan(),
        ])->layout('layouts.app-sidebar', ['title' => $this->immobilisation->numero]);
    }

    /**
     * Plan complet, de l'exercice de mise en service jusqu'au solde du bien.
     *
     * Les exercices déjà comptabilisés portent le montant réellement écrit ;
     * les suivants sont des projections calculées à la volée — rien n'est stocké,
     * donc rien ne peut devenir périmé.
     *
     * @return list<array{exercice: int, moisEcoules: int, dotationCentimes: int, cumulCentimes: int, valeurNetteCentimes: int, comptabilisee: bool}>
     */
    private function construirePlan(): array
    {
        $calculator = app(PlanAmortissementCalculator::class);
        $exerciceService = app(ExerciceService::class);

        $exerciceDebut = $exerciceService->anneeForDate(
            CarbonImmutable::parse($this->immobilisation->date_mise_en_service->toDateString())
        );

        $montantCentimes = $this->immobilisation->montantAcquisitionCentimes();
        $plan = [];
        $cumul = 0;
        $exercice = $exerciceDebut;

        // Borne dure : la durée en mois ne peut pas s'étaler sur plus d'exercices
        // que (durée / 12) + 2, ce qui protège d'une boucle infinie si le calcul
        // venait à stagner.
        $borne = intdiv((int) $this->immobilisation->duree_mois, 12) + 2;

        for ($i = 0; $i < $borne && $cumul < $montantCentimes; $i++) {
            $dotationRecalculee = $calculator->dotationCentimes($this->immobilisation, $exercice, $cumul);

            $dotationEnregistree = $this->immobilisation->dotations->firstWhere('exercice', $exercice);
            $comptabilisee = $dotationEnregistree !== null;

            $dotation = $comptabilisee
                ? (int) round(((float) $dotationEnregistree->montant) * 100)
                : $dotationRecalculee;

            $cumul += $dotation;

            $plan[] = [
                'exercice' => $exercice,
                'moisEcoules' => $calculator->moisEcoules($this->immobilisation, $exercice),
                'dotationCentimes' => $dotation,
                'cumulCentimes' => $cumul,
                'valeurNetteCentimes' => $montantCentimes - $cumul,
                'comptabilisee' => $comptabilisee,
            ];

            $exercice++;
        }

        return $plan;
    }
}
