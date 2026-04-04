<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Transaction;
use App\Services\FactureService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class FactureEdit extends Component
{
    public Facture $facture;

    public string $date = '';

    public ?int $compte_bancaire_id = null;

    public ?string $conditions_reglement = null;

    public ?string $mentions_legales = null;

    public ?string $notes = null;

    public string $newTexteLibelle = '';

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Compta);
    }

    public function mount(Facture $facture): void
    {
        if ($facture->statut !== StatutFacture::Brouillon) {
            $this->redirect(route($this->espacePrefix().'.factures.show', $facture));

            return;
        }

        $this->facture = $facture;
        $this->date = $facture->date->format('Y-m-d');
        $this->compte_bancaire_id = $facture->compte_bancaire_id;
        $this->conditions_reglement = $facture->conditions_reglement;
        $this->mentions_legales = $facture->mentions_legales;
        $this->notes = $facture->notes;
    }

    public function toggleTransaction(int $transactionId): void
    {
        if (! $this->canEdit) { return; }

        $service = app(FactureService::class);
        $selectedIds = $this->facture->transactions()->pluck('transactions.id')->toArray();

        try {
            if (in_array($transactionId, $selectedIds, true)) {
                $service->retirerTransaction($this->facture, $transactionId);
            } else {
                $service->ajouterTransactions($this->facture, [$transactionId]);
            }

            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function updateLibelle(int $ligneId, string $libelle): void
    {
        if (! $this->canEdit) { return; }

        try {
            app(FactureService::class)->majLibelle($this->facture, $ligneId, $libelle);
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function moveUp(int $ligneId): void
    {
        if (! $this->canEdit) { return; }

        try {
            app(FactureService::class)->majOrdre($this->facture, $ligneId, 'up');
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function moveDown(int $ligneId): void
    {
        if (! $this->canEdit) { return; }

        try {
            app(FactureService::class)->majOrdre($this->facture, $ligneId, 'down');
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function addTexte(): void
    {
        if (! $this->canEdit) { return; }

        if (trim($this->newTexteLibelle) === '') {
            return;
        }

        try {
            app(FactureService::class)->ajouterLigneTexte($this->facture, $this->newTexteLibelle);
            $this->newTexteLibelle = '';
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function deleteTexte(int $ligneId): void
    {
        if (! $this->canEdit) { return; }

        try {
            app(FactureService::class)->supprimerLigne($this->facture, $ligneId);
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function sauvegarder(): void
    {
        if (! $this->canEdit) { return; }

        $this->facture->update([
            'date' => $this->date,
            'compte_bancaire_id' => $this->compte_bancaire_id,
            'conditions_reglement' => $this->conditions_reglement,
            'mentions_legales' => $this->mentions_legales,
            'notes' => $this->notes,
        ]);

        session()->flash('success', 'Brouillon enregistre.');
    }

    public function valider(): void
    {
        if (! $this->canEdit) { return; }

        $this->sauvegarder();

        try {
            app(FactureService::class)->valider($this->facture);
            $this->redirect(route($this->espacePrefix().'.factures.show', $this->facture));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function supprimer(): void
    {
        if (! $this->canEdit) { return; }

        try {
            app(FactureService::class)->supprimerBrouillon($this->facture);
            $this->redirect(route($this->espacePrefix().'.factures'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $selectedIds = $this->facture->transactions()->pluck('transactions.id')->toArray();

        $transactions = Transaction::where(function ($query) {
            $query->where('type', TypeTransaction::Recette)
                ->where('tiers_id', $this->facture->tiers_id)
                ->whereDoesntHave('factures', fn ($q) => $q->whereIn('statut', [StatutFacture::Brouillon, StatutFacture::Validee])
                );
        })
            ->orWhere(function ($q) {
                $q->whereHas('factures', fn ($fq) => $fq->where('factures.id', $this->facture->id)
                );
            })
            ->orderByDesc('date')
            ->get();

        $lignes = $this->facture->lignes()->get();

        $totalLignes = $lignes->where('type', TypeLigneFacture::Montant)->sum('montant');

        $comptesBancaires = CompteBancaire::where('est_systeme', false)
            ->orderBy('nom')
            ->get();

        return view('livewire.facture-edit', [
            'transactions' => $transactions,
            'selectedIds' => $selectedIds,
            'lignes' => $lignes,
            'totalLignes' => $totalLignes,
            'comptesBancaires' => $comptesBancaires,
        ]);
    }

    private function espacePrefix(): string
    {
        return (request()->attributes->get('espace') ?? Espace::Compta)->value;
    }
}
