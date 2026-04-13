<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\StatutReglement;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class RemiseBancaireValidation extends Component
{
    public RemiseBancaire $remise;

    /** @var list<int> */
    public array $selectedTransactionIds = [];

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;

        $this->selectedTransactionIds = Transaction::where('remise_id', $remise->id)
            ->pluck('id')
            ->all();
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Gestion);
    }

    public function comptabiliser(): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            $service = app(RemiseBancaireService::class);

            $alreadyComptabilisee = Transaction::where('remise_id', $this->remise->id)
                ->where('statut_reglement', StatutReglement::Recu->value)
                ->exists();

            if ($alreadyComptabilisee) {
                $service->modifier($this->remise, $this->selectedTransactionIds);
            } else {
                $service->comptabiliser($this->remise, $this->selectedTransactionIds);
            }

            session()->flash('success', 'Remise comptabilisée avec succès.');
            $this->redirect(route('banques.remises.index'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $transactions = Transaction::whereIn('id', $this->selectedTransactionIds)
            ->with(['tiers', 'compte'])
            ->get();

        $totalMontant = $transactions->sum('montant_total');

        return view('livewire.remise-bancaire-validation', [
            'transactions' => $transactions,
            'totalMontant' => $totalMontant,
            'countTotal' => $transactions->count(),
        ]);
    }
}
