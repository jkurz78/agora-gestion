<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Models\CompteBancaire;
use App\Models\RemiseBancaire;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

final class RemiseBancaireList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public bool $showCreateForm = false;

    public string $date = '';

    public string $compte_cible_id = '';

    public string $mode_paiement = 'cheque';

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Gestion);
    }

    public function create(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->validate([
            'date' => ['required', 'date'],
            'compte_cible_id' => ['required', 'exists:comptes_bancaires,id'],
            'mode_paiement' => ['required', 'in:cheque,especes'],
        ]);

        $remise = app(RemiseBancaireService::class)->creer([
            'date' => $this->date,
            'mode_paiement' => $this->mode_paiement,
            'compte_cible_id' => (int) $this->compte_cible_id,
        ]);

        $this->redirect(route('gestion.remises-bancaires.selection', $remise));
    }

    public function supprimer(int $id): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            $remise = RemiseBancaire::findOrFail($id);
            app(RemiseBancaireService::class)->supprimer($remise);
            session()->flash('success', 'Remise supprimée.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $remises = RemiseBancaire::with(['compteCible', 'virement', 'reglements'])
            ->orderByDesc('date')
            ->orderByDesc('numero')
            ->paginate(20);

        $comptes = CompteBancaire::where('est_systeme', false)
            ->orderBy('nom')
            ->get();

        return view('livewire.remise-bancaire-list', [
            'remises' => $remises,
            'comptes' => $comptes,
        ]);
    }
}
