<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\CotisationService;
use App\Services\ExerciceService;
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

    public function showNewForm(): void
    {
        $this->resetForm();
        $this->date_paiement = app(ExerciceService::class)->defaultDate();
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset(['tiers_id', 'sous_categorie_id', 'montant', 'date_paiement', 'mode_paiement', 'compte_id', 'showForm']);
        $this->resetValidation();
    }

    #[On('open-cotisation-for-tiers')]
    public function openForTiers(?int $tiersId = null): void
    {
        $this->resetForm();
        $this->date_paiement = app(ExerciceService::class)->defaultDate();
        $this->showForm = true;
        if ($tiersId !== null) {
            $this->tiers_id = $tiersId;
        }
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        $validated = $this->validate([
            'tiers_id'         => ['required', 'exists:tiers,id'],
            'sous_categorie_id' => ['required', 'exists:sous_categories,id'],
            'montant'          => ['required', 'numeric', 'min:0.01'],
            'date_paiement'    => ['required', 'date', 'after_or_equal:' . $dateDebut, 'before_or_equal:' . $dateFin],
            'mode_paiement'    => ['required', 'string'],
            'compte_id'        => ['nullable', 'exists:comptes_bancaires,id'],
        ], [
            'tiers_id.required'           => 'Veuillez sélectionner un tiers.',
            'date_paiement.after_or_equal'  => 'La date doit être dans l\'exercice en cours (à partir du ' . $range['start']->format('d/m/Y') . ').',
            'date_paiement.before_or_equal' => 'La date doit être dans l\'exercice en cours (jusqu\'au ' . $range['end']->format('d/m/Y') . ').',
        ]);

        $tiers = Tiers::findOrFail($validated['tiers_id']);

        $data = [
            'sous_categorie_id' => $validated['sous_categorie_id'],
            'montant'           => $validated['montant'],
            'date_paiement'     => $validated['date_paiement'],
            'mode_paiement'     => $validated['mode_paiement'],
            'compte_id'         => $validated['compte_id'],
            'exercice'          => $exerciceService->current(),
        ];

        app(CotisationService::class)->create($tiers, $data);

        $this->dispatch('cotisation-saved');
        $this->resetForm();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.cotisation-form', [
            'postescotisation' => SousCategorie::where('pour_cotisations', true)->orderBy('nom')->get(),
            'comptes'          => CompteBancaire::where('actif_dons_cotisations', true)->orderBy('nom')->get(),
            'modesPaiement'    => ModePaiement::cases(),
        ]);
    }
}
