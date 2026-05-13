<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\Adhesion;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Services\Adhesion\NouvelleAdhesionDTO;
use App\Services\AdhesionService;
use App\Services\ExerciceService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class NouvelleAdhesionModal extends Component
{
    public bool $visible = false;

    public bool $gratuite = false;

    public ?int $tiersId = null;

    public ?int $formuleId = null;

    public ?int $exercice = null;

    public ?string $dateDebut = null;

    public float $montant = 0.0;

    public ?string $notes = null;

    public ?string $datePaiement = null;

    public ?string $modePaiement = null;

    public ?int $compteId = null;

    public ?string $reference = null;

    public ?string $errorMessage = null;

    #[On('nouvelle-adhesion')]
    public function open(bool $gratuite = false): void
    {
        $this->reset(['tiersId', 'formuleId', 'exercice', 'dateDebut', 'notes', 'datePaiement', 'modePaiement', 'compteId', 'reference', 'errorMessage']);
        $this->gratuite = $gratuite;
        $this->montant = 0.0;
        $this->exercice = app(ExerciceService::class)->current();
        $this->datePaiement = Carbon::today()->toDateString();
        $this->visible = true;
    }

    public function close(): void
    {
        $this->visible = false;
    }

    public function updatedFormuleId(): void
    {
        if ($this->formuleId === null) {
            return;
        }
        $formule = FormuleAdhesion::find($this->formuleId);
        if ($formule === null) {
            return;
        }
        if ($formule->montant_par_defaut !== null && $this->montant === 0.0 && ! $this->gratuite) {
            $this->montant = (float) $formule->montant_par_defaut;
        }
        if ($formule->isModeDuree() && $this->dateDebut === null) {
            $this->dateDebut = Carbon::today()->toDateString();
        }
    }

    /**
     * Computed : date_fin calculée depuis date_debut selon l'unité de la formule (mois ou jours).
     */
    #[Computed]
    public function dateFinCalculee(): ?string
    {
        if ($this->formuleId === null || $this->dateDebut === null) {
            return null;
        }
        $formule = FormuleAdhesion::find($this->formuleId);
        if ($formule === null || ! $formule->isModeDuree()) {
            return null;
        }

        $debut = Carbon::parse($this->dateDebut);

        if ($formule->duree_jours !== null) {
            return $debut->addDays((int) $formule->duree_jours)->subDay()->toDateString();
        }

        if ($formule->duree_mois !== null) {
            return $debut->addMonths((int) $formule->duree_mois)->subDay()->toDateString();
        }

        return null;
    }

    public function submit(AdhesionService $service): void
    {
        $this->errorMessage = null;
        $rules = [
            'tiersId' => ['required', 'integer'],
            'formuleId' => ['required', 'integer'],
            'montant' => ['numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
        if ($this->montant > 0) {
            $rules['datePaiement'] = ['required', 'date'];
            $rules['modePaiement'] = ['required', 'string', Rule::in(array_column(ModePaiement::cases(), 'value'))];
            $rules['compteId'] = ['required', 'integer'];
        }
        $this->validate($rules);

        $formule = FormuleAdhesion::findOrFail($this->formuleId);

        $dto = new NouvelleAdhesionDTO(
            tiersId: (int) $this->tiersId,
            formuleId: (int) $this->formuleId,
            exercice: $formule->isModeExercice() ? ($this->exercice ?? app(ExerciceService::class)->current()) : null,
            dateDebut: $formule->isModeDuree() && $this->dateDebut !== null ? Carbon::parse($this->dateDebut) : null,
            montant: $this->montant,
            notes: $this->notes,
            datePaiement: $this->montant > 0 ? $this->datePaiement : null,
            modePaiement: $this->montant > 0 && $this->modePaiement !== null ? ModePaiement::from($this->modePaiement) : null,
            compteId: $this->montant > 0 ? $this->compteId : null,
            reference: $this->reference,
        );

        try {
            $service->creerDepuisWizard($dto, auth()->user());
        } catch (DomainException|InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        session()->flash('success', 'Adhésion créée avec succès.');
        $this->dispatch('adhesion-creee');
        $this->visible = false;
    }

    public function render(): View
    {
        $formulesManuelles = FormuleAdhesion::query()
            ->where('actif', true)
            ->where('est_helloasso', false)
            ->orderBy('nom')
            ->get();

        $formulesHelloAsso = FormuleAdhesion::query()
            ->where('actif', true)
            ->where('est_helloasso', true)
            ->orderBy('nom')
            ->get();

        $formules = $formulesManuelles->merge($formulesHelloAsso);

        $availableYears = app(ExerciceService::class)->openYears();
        // Saisie manuelle : exclut les comptes alimentés par intégration externe
        // (HelloAsso etc.) — les adhésions HelloAsso passent par la sync, pas le wizard.
        $comptes = CompteBancaire::saisieManuelle()
            ->orderBy('nom')
            ->get();
        $modesPaiement = ModePaiement::cases();
        $notesSuggestions = Adhesion::query()
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->whereNull('transaction_id')
            ->distinct()
            ->orderBy('notes')
            ->limit(20)
            ->pluck('notes')
            ->all();

        return view('livewire.nouvelle-adhesion-modal', compact('formules', 'formulesManuelles', 'formulesHelloAsso', 'availableYears', 'comptes', 'modesPaiement', 'notesSuggestions'));
    }
}
