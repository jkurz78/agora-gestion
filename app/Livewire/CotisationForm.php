<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Membre;
use App\Services\CotisationService;
use App\Services\ExerciceService;
use Livewire\Component;

final class CotisationForm extends Component
{
    public Membre $membre;

    public int $exercice;
    public string $montant = '';
    public string $date_paiement = '';
    public string $mode_paiement = '';
    public string $compte_id = '';

    public function mount(Membre $membre): void
    {
        $this->membre = $membre;

        $exerciceService = app(ExerciceService::class);
        $this->exercice = $exerciceService->current();
        $this->date_paiement = now()->format('Y-m-d');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'exercice' => ['required', 'integer'],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'date_paiement' => ['required', 'date'],
            'mode_paiement' => ['required', 'string'],
            'compte_id' => ['nullable'],
        ]);

        // Convert empty string to null for compte_id
        $validated['compte_id'] = $validated['compte_id'] !== '' ? (int) $validated['compte_id'] : null;

        app(CotisationService::class)->create($this->membre, $validated);

        $this->reset(['montant', 'mode_paiement', 'compte_id']);
        $this->date_paiement = now()->format('Y-m-d');

        $this->membre->load('cotisations.compte');
    }

    public function delete(int $id): void
    {
        $cotisation = Cotisation::findOrFail($id);

        app(CotisationService::class)->delete($cotisation);

        $this->membre->load('cotisations.compte');
    }

    public function render()
    {
        $exerciceService = app(ExerciceService::class);

        return view('livewire.cotisation-form', [
            'cotisations' => $this->membre->cotisations()->with('compte')->latest()->get(),
            'comptes' => CompteBancaire::orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
            'exercices' => $exerciceService->available(),
            'exerciceService' => $exerciceService,
        ]);
    }
}
