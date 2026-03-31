<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutFacture;
use App\Models\Facture;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class FactureShow extends Component
{
    public Facture $facture;

    public function mount(Facture $facture): void
    {
        if ($facture->statut === StatutFacture::Brouillon) {
            $this->redirect(route('gestion.factures.edit', $facture));

            return;
        }

        $facture->load(['tiers', 'compteBancaire', 'lignes', 'transactions']);
        $this->facture = $facture;
    }

    public function render(): View
    {
        $montantRegle = $this->facture->montantRegle();
        $isAcquittee = $this->facture->isAcquittee();

        return view('livewire.facture-show', [
            'montantRegle' => $montantRegle,
            'isAcquittee' => $isAcquittee,
        ]);
    }
}
