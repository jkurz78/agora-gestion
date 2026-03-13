<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Services\RapprochementBancaireService;
use Livewire\Component;
use Livewire\WithPagination;

final class RapprochementList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public ?int $compte_id = null;

    public bool $showCreateForm = false;

    public string $date_fin = '';

    public string $solde_fin = '';

    public function updatedCompteId(): void
    {
        $this->showCreateForm = false;
        $this->date_fin = '';
        $this->solde_fin = '';
        $this->resetValidation();
        $this->resetPage();
    }

    public function create(): void
    {
        $this->validate([
            'compte_id' => ['required', 'exists:comptes_bancaires,id'],
            'date_fin' => ['required', 'date'],
            'solde_fin' => ['required', 'numeric'],
        ]);

        try {
            $compte = CompteBancaire::findOrFail($this->compte_id);
            $rapprochement = app(RapprochementBancaireService::class)
                ->create($compte, $this->date_fin, (float) $this->solde_fin);

            $this->showCreateForm = false;
            $this->date_fin = '';
            $this->solde_fin = '';
            $this->resetValidation();

            $this->redirect(route('rapprochement.detail', $rapprochement));
        } catch (\RuntimeException $e) {
            $this->addError('date_fin', $e->getMessage());
        }
    }

    public function render(): \Illuminate\View\View
    {
        $comptes = CompteBancaire::orderBy('nom')->get();
        $rapprochements = collect();
        $aEnCours = false;
        $soldeOuverture = null;

        if ($this->compte_id) {
            $aEnCours = RapprochementBancaire::where('compte_id', $this->compte_id)
                ->whereNull('verrouille_at')
                ->exists();

            $rapprochements = RapprochementBancaire::where('compte_id', $this->compte_id)
                ->orderByDesc('date_fin')
                ->paginate(20);

            if (! $aEnCours) {
                $compte = CompteBancaire::find($this->compte_id);
                if ($compte) {
                    $soldeOuverture = app(RapprochementBancaireService::class)
                        ->calculerSoldeOuverture($compte);
                }
            }
        }

        return view('livewire.rapprochement-list', [
            'comptes' => $comptes,
            'rapprochements' => $rapprochements,
            'aEnCours' => $aEnCours,
            'soldeOuverture' => $soldeOuverture,
        ]);
    }
}
