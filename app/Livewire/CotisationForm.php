<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\CotisationService;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class CotisationForm extends Component
{
    public ?int $tiers_id = null;

    public ?int $sous_categorie_id = null;

    public string $montant = '';

    public string $date_paiement = '';

    public string $mode_paiement = '';

    public ?int $compte_id = null;

    public bool $showForm = false;

    public ?int $cotisationId = null;

    public bool $tiersLocked = false;

    public function showNewForm(): void
    {
        $this->resetForm();
        $this->date_paiement = app(ExerciceService::class)->defaultDate();
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset(['tiers_id', 'sous_categorie_id', 'montant', 'date_paiement', 'mode_paiement', 'compte_id', 'showForm', 'cotisationId', 'tiersLocked']);
        $this->resetValidation();
    }

    #[On('open-cotisation-form')]
    public function open(?int $id = null): void
    {
        $this->resetForm();
        if ($id !== null) {
            $cotisation = Cotisation::findOrFail($id);
            $this->cotisationId = $cotisation->id;
            $this->tiers_id = $cotisation->tiers_id;
            $this->sous_categorie_id = $cotisation->sous_categorie_id;
            $this->montant = (string) $cotisation->montant;
            $this->date_paiement = $cotisation->date_paiement->format('Y-m-d');
            $this->mode_paiement = $cotisation->mode_paiement->value;
            $this->compte_id = $cotisation->compte_id;
            $this->tiersLocked = true;
        } else {
            $this->date_paiement = app(ExerciceService::class)->defaultDate();
            $defaults = session('cotisation_defaults', []);
            $this->sous_categorie_id = $defaults['sous_categorie_id'] ?? null;
            $this->mode_paiement = $defaults['mode_paiement'] ?? '';
            $this->compte_id = $defaults['compte_id'] ?? null;
        }
        $this->showForm = true;
    }

    #[On('open-cotisation-for-tiers')]
    public function openForTiers(?int $tiersId = null): void
    {
        $this->open(null);
        if ($tiersId !== null) {
            $this->tiers_id = $tiersId;
            $this->tiersLocked = true;
        }
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        $validated = $this->validate([
            'tiers_id' => ['required', 'exists:tiers,id'],
            'sous_categorie_id' => ['required', 'exists:sous_categories,id'],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'date_paiement' => ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
            'mode_paiement' => ['required', 'string'],
            'compte_id' => ['nullable', 'exists:comptes_bancaires,id'],
        ], [
            'tiers_id.required' => 'Veuillez sélectionner un tiers.',
            'date_paiement.after_or_equal' => 'La date doit être dans l\'exercice en cours (à partir du '.$range['start']->format('d/m/Y').').',
            'date_paiement.before_or_equal' => 'La date doit être dans l\'exercice en cours (jusqu\'au '.$range['end']->format('d/m/Y').').',
        ]);

        $data = [
            'sous_categorie_id' => $validated['sous_categorie_id'],
            'montant' => $validated['montant'],
            'date_paiement' => $validated['date_paiement'],
            'mode_paiement' => $validated['mode_paiement'],
            'compte_id' => $validated['compte_id'],
            'exercice' => $exerciceService->current(),
        ];

        if ($this->cotisationId !== null) {
            $cotisation = Cotisation::findOrFail($this->cotisationId);
            $cotisation->update($data);
        } else {
            $tiers = Tiers::findOrFail($validated['tiers_id']);
            app(CotisationService::class)->create($tiers, $data);
        }

        session(['cotisation_defaults' => [
            'sous_categorie_id' => $this->sous_categorie_id,
            'mode_paiement' => $this->mode_paiement,
            'compte_id' => $this->compte_id,
        ]]);
        $this->dispatch('cotisation-saved');
        $this->resetForm();
    }

    public function render(): View
    {
        return view('livewire.cotisation-form', [
            'postescotisation' => SousCategorie::where('pour_cotisations', true)->orderBy('nom')->get(),
            'comptes' => CompteBancaire::where('actif_dons_cotisations', true)->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
            'tiersNom' => $this->tiersLocked && $this->tiers_id
                ? (Tiers::find($this->tiers_id)?->displayName() ?? '')
                : '',
        ]);
    }
}
