<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutFacture;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Services\FactureService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class FactureShow extends Component
{
    public Facture $facture;

    /** @var array<int> */
    public array $selectedTransactionIds = [];

    public ?int $encaissementCompteId = null;

    public function mount(Facture $facture): void
    {
        if ($facture->statut === StatutFacture::Brouillon) {
            $this->redirect(route('gestion.factures.edit', $facture));

            return;
        }

        $facture->load(['tiers', 'compteBancaire', 'lignes', 'transactions.compte']);
        $this->facture = $facture;
    }

    public function toggleTransaction(int $id): void
    {
        if (in_array($id, $this->selectedTransactionIds, true)) {
            $this->selectedTransactionIds = array_values(array_diff($this->selectedTransactionIds, [$id]));
        } else {
            $this->selectedTransactionIds[] = $id;
        }
    }

    public function encaisser(): void
    {
        if ($this->encaissementCompteId === null) {
            session()->flash('error', 'Veuillez sélectionner un compte bancaire de destination.');

            return;
        }

        if (count($this->selectedTransactionIds) === 0) {
            session()->flash('error', 'Veuillez sélectionner au moins une transaction à encaisser.');

            return;
        }

        try {
            app(FactureService::class)->encaisser(
                $this->facture,
                $this->selectedTransactionIds,
                $this->encaissementCompteId,
            );

            $this->selectedTransactionIds = [];
            $this->encaissementCompteId = null;
            $this->facture->load(['transactions.compte']);

            session()->flash('success', 'Encaissement enregistré.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $montantRegle = $this->facture->montantRegle();
        $isAcquittee = $this->facture->isAcquittee();

        $transactionsAEncaisser = $this->facture->transactions
            ->filter(fn ($t) => $t->compte->est_systeme);

        $comptesDestination = CompteBancaire::where('est_systeme', false)
            ->orderBy('nom')
            ->get();

        return view('livewire.facture-show', [
            'montantRegle' => $montantRegle,
            'isAcquittee' => $isAcquittee,
            'transactionsAEncaisser' => $transactionsAEncaisser,
            'comptesDestination' => $comptesDestination,
        ]);
    }
}
