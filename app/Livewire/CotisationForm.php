<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Services\CotisationService;
use App\Services\ExerciceService;
use Livewire\Component;

final class CotisationForm extends Component
{
    public Tiers $tiers;

    public string $montant = '';

    public string $date_paiement = '';

    public string $mode_paiement = '';

    public string $compte_id = '';

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
        $this->date_paiement = app(ExerciceService::class)->defaultDate();
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        $validated = $this->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'date_paiement' => ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
            'mode_paiement' => ['required', 'string'],
            'compte_id' => ['nullable'],
        ], [
            'date_paiement.after_or_equal' => 'La date de paiement doit être dans l\'exercice en cours (à partir du '.$range['start']->format('d/m/Y').').',
            'date_paiement.before_or_equal' => 'La date de paiement doit être dans l\'exercice en cours (jusqu\'au '.$range['end']->format('d/m/Y').').',
        ]);

        $validated['exercice'] = app(ExerciceService::class)->current();
        // Convert empty string to null for compte_id
        $validated['compte_id'] = $validated['compte_id'] !== '' ? (int) $validated['compte_id'] : null;

        app(CotisationService::class)->create($this->tiers, $validated);

        $this->reset(['montant', 'mode_paiement', 'compte_id']);
        $this->date_paiement = app(ExerciceService::class)->defaultDate();

        $this->tiers->load('cotisations.compte');
    }

    public function delete(int $id): void
    {
        $cotisation = Cotisation::findOrFail($id);

        try {
            app(CotisationService::class)->delete($cotisation);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->tiers->load('cotisations.compte');
    }

    public function render()
    {
        return view('livewire.cotisation-form', [
            'cotisations' => $this->tiers->cotisations()->with('compte')->latest()->get(),
            'comptes' => CompteBancaire::where('actif_dons_cotisations', true)->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
        ]);
    }
}
