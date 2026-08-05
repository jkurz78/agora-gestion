<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Exceptions\ExerciceCloturedException;
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Exceptions\Immobilisation\SuppressionInterditeException;
use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\ImmobilisationService;
use App\Services\Immobilisation\PlanAmortissementCalculator;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Component;

final class ImmobilisationShow extends Component
{
    public Immobilisation $immobilisation;

    // ── État du formulaire d'édition ─────────────────────────────
    public bool $showEditModal = false;

    public string $libelle = '';

    public int $quantite = 1;

    public int $duree_mois = 60;

    public string $date_mise_en_service = '';

    public string $notes = '';

    public string $flashMessage = '';

    public string $flashType = '';

    public function mount(Immobilisation $immobilisation): void
    {
        $this->immobilisation = $immobilisation;
    }

    public function render(): View
    {
        $this->immobilisation->load(['compte', 'compteAmortissement', 'dotations.transaction', 'transaction.tiers']);

        return view('livewire.immobilisations.immobilisation-show', [
            'plan' => $this->construirePlan(),
            'dureesUsuelles' => ImmobilisationIndex::DUREES_USUELLES,
        ])->layout('layouts.app-sidebar', ['title' => $this->immobilisation->numero]);
    }

    /**
     * Ouvre le formulaire d'édition, pré-rempli avec les valeurs actuelles.
     * `montant_acquisition` et `compte_id` n'y figurent pas : ils sont
     * affichés en lecture seule directement dans la vue.
     */
    public function ouvrirEdition(): void
    {
        $this->libelle = $this->immobilisation->libelle;
        $this->quantite = (int) $this->immobilisation->quantite;
        $this->duree_mois = (int) $this->immobilisation->duree_mois;
        $this->date_mise_en_service = $this->immobilisation->date_mise_en_service->toDateString();
        $this->notes = $this->immobilisation->notes ?? '';
        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function fermerEdition(): void
    {
        $this->showEditModal = false;
    }

    public function enregistrerModification(): void
    {
        $this->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'quantite' => ['required', 'integer', 'min:1'],
            'date_mise_en_service' => ['required', 'date'],
            'duree_mois' => ['required', 'integer', 'min:1', 'max:600'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'libelle' => 'libellé',
            'date_mise_en_service' => 'date de mise en service',
            'duree_mois' => 'durée',
        ]);

        try {
            $this->immobilisation = app(ImmobilisationService::class)->modifier(
                immobilisation: $this->immobilisation,
                libelle: $this->libelle,
                quantite: $this->quantite,
                dureeMois: $this->duree_mois,
                dateMiseEnService: CarbonImmutable::parse($this->date_mise_en_service),
                notes: $this->notes === '' ? null : $this->notes,
            );
        } catch (MiseEnServiceAnterieureException $e) {
            $this->addError('date_mise_en_service', $e->getMessage());

            return;
        }

        $this->showEditModal = false;
        $this->flashMessage = 'Immobilisation modifiée.';
        $this->flashType = 'success';
    }

    /**
     * Supprime la fiche et sa transaction d'acquisition, puis redirige vers le
     * livre — la fiche affichée n'existe plus. En cas de refus (dotations déjà
     * générées, exercice clôturé), l'utilisateur reste sur la fiche avec un
     * message d'erreur.
     */
    public function supprimer(): void
    {
        try {
            app(ImmobilisationService::class)->supprimer($this->immobilisation);
        } catch (SuppressionInterditeException|ExerciceCloturedException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'danger';

            return;
        }

        session()->flash('success', 'Immobilisation supprimée, ainsi que son écriture d’acquisition.');
        $this->redirect(route('immobilisations.index'), navigate: false);
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
