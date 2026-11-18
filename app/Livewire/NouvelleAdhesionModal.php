<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Models\Adhesion;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\Tiers;
use App\Services\Adhesion\NouvelleAdhesionDTO;
use App\Services\AdhesionService;
use App\Services\ExerciceService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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

    /** Renseigné en mode modification d'une adhésion offerte ; null en création. */
    #[Locked]
    public ?int $adhesionId = null;

    #[On('nouvelle-adhesion')]
    public function open(bool $gratuite = false): void
    {
        $this->reset(['adhesionId', 'tiersId', 'formuleId', 'exercice', 'dateDebut', 'notes', 'datePaiement', 'modePaiement', 'compteId', 'reference', 'errorMessage']);
        $this->gratuite = $gratuite;
        $this->montant = 0.0;
        $this->exercice = app(ExerciceService::class)->current();
        $this->datePaiement = Carbon::today()->toDateString();
        $this->visible = true;
    }

    /**
     * Mode modification : seule une adhésion offerte s'ouvre ici. Le tiers ne change
     * pas et elle reste offerte ; formule, période et motif sont repris.
     */
    #[On('modifier-adhesion-offerte')]
    public function ouvrirModification(int $adhesionId): void
    {
        $this->exigerEcriture();

        $adhesion = Adhesion::findOrFail($adhesionId);
        if (! $adhesion->estGratuite()) {
            return;
        }

        $this->reset(['tiersId', 'formuleId', 'exercice', 'dateDebut', 'notes', 'datePaiement', 'modePaiement', 'compteId', 'reference', 'errorMessage']);
        $this->resetValidation();
        $this->adhesionId = (int) $adhesion->id;
        $this->gratuite = true;
        $this->montant = 0.0;
        $this->tiersId = (int) $adhesion->tiers_id;
        $this->formuleId = $adhesion->formule_adhesion_id !== null ? (int) $adhesion->formule_adhesion_id : null;
        $this->exercice = $adhesion->exercice;
        $this->dateDebut = $adhesion->date_debut?->toDateString();
        $this->notes = $adhesion->notes;
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
        // Une formule à dates fixes impose sa période : pas de date de début à proposer.
        if ($formule->isModeDuree() && ! $formule->aDatesFixes() && $this->dateDebut === null) {
            $this->dateDebut = Carbon::today()->toDateString();
        }
    }

    /**
     * Computed : date_fin de l'adhésion à créer.
     *  - formule à dates fixes (HelloAsso) : la fin imposée par la formule ;
     *  - formule en durée : calculée depuis date_debut selon l'unité (mois ou jours).
     */
    #[Computed]
    public function dateFinCalculee(): ?string
    {
        if ($this->formuleId === null) {
            return null;
        }
        $formule = FormuleAdhesion::find($this->formuleId);
        if ($formule === null || ! $formule->isModeDuree()) {
            return null;
        }

        if ($formule->aDatesFixes()) {
            return $formule->helloasso_end_date?->toDateString();
        }

        if ($this->dateDebut === null) {
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

        if ($this->adhesionId !== null) {
            $this->enregistrerModification($service);

            return;
        }

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

    private function enregistrerModification(AdhesionService $service): void
    {
        $this->exigerEcriture();
        $this->validate([
            'formuleId' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $adhesion = Adhesion::findOrFail($this->adhesionId);
        $formule = FormuleAdhesion::findOrFail($this->formuleId);

        // Une formule permanente garde sa date de début en modification (à la création,
        // c'est aujourd'hui) ; une formule à dates fixes impose les siennes.
        $avecDateDebut = ($formule->isModeDuree() && ! $formule->aDatesFixes()) || $formule->isModeIllimite();

        try {
            $service->modifierOfferte(
                $adhesion,
                (int) $formule->id,
                $formule->isModeExercice() ? $this->exercice : null,
                $avecDateDebut && $this->dateDebut !== null ? Carbon::parse($this->dateDebut) : null,
                $this->notes,
            );
        } catch (DomainException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        session()->flash('success', 'Adhésion modifiée.');
        $this->dispatch('adhesion-modifiee');
        $this->visible = false;
    }

    private function exigerEcriture(): void
    {
        $role = RoleAssociation::tryFrom(auth()->user()?->currentRole() ?? '');
        if (! ($role?->canWrite(Espace::Gestion) ?? false)) {
            abort(403);
        }
    }

    public function render(): View
    {
        // En modification, la formule déjà portée reste proposée même désactivée depuis :
        // sinon la liste s'afficherait vide et forcerait un changement de formule.
        $formuleActuelleId = $this->adhesionId !== null ? $this->formuleId : null;
        $proposees = fn ($q) => $q->where('actif', true)
            ->when($formuleActuelleId !== null, fn ($q) => $q->orWhere('id', $formuleActuelleId));

        $formulesManuelles = FormuleAdhesion::query()
            ->where($proposees)
            ->where('est_helloasso', false)
            ->orderBy('nom')
            ->get();

        $formulesHelloAsso = FormuleAdhesion::query()
            ->where($proposees)
            ->where('est_helloasso', true)
            ->orderBy('nom')
            ->get();

        $formules = $formulesManuelles->merge($formulesHelloAsso);

        $availableYears = app(ExerciceService::class)->openYears();
        // Une adhésion offerte n'a pas d'écriture comptable : son exercice reste proposé
        // en modification même clôturé, pour pouvoir corriger le motif sans la déplacer.
        if ($this->adhesionId !== null && $this->exercice !== null && ! in_array($this->exercice, $availableYears, true)) {
            $availableYears[] = $this->exercice;
            rsort($availableYears);
        }
        $tiersNom = $this->adhesionId !== null && $this->tiersId !== null
            ? Tiers::find($this->tiersId)?->displayName()
            : null;
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

        return view('livewire.nouvelle-adhesion-modal', compact('formules', 'formulesManuelles', 'formulesHelloAsso', 'availableYears', 'comptes', 'modesPaiement', 'notesSuggestions', 'tiersNom'));
    }
}
