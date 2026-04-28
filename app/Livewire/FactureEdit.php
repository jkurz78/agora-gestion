<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\SousCategorie;
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

    // ── Mode paiement prévu (Conditions de règlement) ─────────────────────────

    public ?string $modePaiementPrevu = null;

    // ── Formulaire ajout ligne libre montant ──────────────────────────────────

    public string $nouvelleLigneMontantLibelle = '';

    public string $nouvelleLigneMontantPrixUnitaire = '';

    public string $nouvelleLigneMontantQuantite = '1';

    public ?int $nouvelleLigneMontantSousCategorieId = null;

    public ?int $nouvelleLigneMontantOperationId = null;

    public ?int $nouvelleLigneMontantSeance = null;

    public bool $afficherFormLigneMontant = false;

    // ── Formulaire ajout ligne libre texte ────────────────────────────────────

    public string $nouvelleLigneTexteLibelle = '';

    public bool $afficherFormLigneTexte = false;

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function mount(Facture $facture): void
    {
        if ($facture->statut !== StatutFacture::Brouillon) {
            $this->redirect(route('facturation.factures.show', $facture));

            return;
        }

        $this->facture = $facture;
        $this->date = $facture->date->format('Y-m-d');
        $this->compte_bancaire_id = $facture->compte_bancaire_id;
        $this->conditions_reglement = $facture->conditions_reglement;
        $this->mentions_legales = $facture->mentions_legales;
        $this->notes = $facture->notes;
        $this->modePaiementPrevu = $facture->mode_paiement_prevu?->value;
    }

    public function toggleTransaction(int $transactionId): void
    {
        if (! $this->canEdit) {
            return;
        }

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
        if (! $this->canEdit) {
            return;
        }

        try {
            app(FactureService::class)->majLibelle($this->facture, $ligneId, $libelle);
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function moveUp(int $ligneId): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(FactureService::class)->majOrdre($this->facture, $ligneId, 'up');
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function moveDown(int $ligneId): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(FactureService::class)->majOrdre($this->facture, $ligneId, 'down');
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function addTexte(): void
    {
        if (! $this->canEdit) {
            return;
        }

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
        $this->supprimerLigneEditable($ligneId);
    }

    public function supprimerLigneEditable(int $ligneId): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(FactureService::class)->supprimerLigne($this->facture, $ligneId);
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function updateSousCategorie(int $ligneId, ?string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        $sousCategorieId = ($value === '' || $value === null) ? null : (int) $value;

        try {
            app(FactureService::class)->majSousCategorieLigne($this->facture, $ligneId, $sousCategorieId);
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function updateOperation(int $ligneId, ?string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        $operationId = ($value === '' || $value === null) ? null : (int) $value;

        try {
            app(FactureService::class)->majOperationLigne($this->facture, $ligneId, $operationId);
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function updateSeance(int $ligneId, ?string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        $seance = ($value === '' || $value === null) ? null : (int) $value;

        try {
            app(FactureService::class)->majSeanceLigne($this->facture, $ligneId, $seance);
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function sauvegarder(): void
    {
        if (! $this->canEdit) {
            return;
        }

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
        if (! $this->canEdit) {
            return;
        }

        $this->sauvegarder();

        try {
            app(FactureService::class)->valider($this->facture);
            $this->redirect(route('facturation.factures.show', $this->facture));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function supprimer(): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(FactureService::class)->supprimerBrouillon($this->facture);
            $this->redirect(route('facturation.factures'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Ligne libre montant ───────────────────────────────────────────────────

    public function ouvrirFormLigneLibreMontant(): void
    {
        $this->afficherFormLigneMontant = true;
        $this->afficherFormLigneTexte = false;
    }

    public function annulerFormLigneLibre(): void
    {
        $this->afficherFormLigneMontant = false;
        $this->afficherFormLigneTexte = false;
        $this->resetFormLigneMontant();
        $this->resetFormLigneTexte();
    }

    public function ajouterLigneLibreMontant(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $prixUnitaire = (float) $this->nouvelleLigneMontantPrixUnitaire;
        $quantite = (float) $this->nouvelleLigneMontantQuantite;

        $this->validate([
            'nouvelleLigneMontantLibelle' => ['required', 'string', 'max:255'],
            'nouvelleLigneMontantPrixUnitaire' => ['required', 'numeric', 'gt:0'],
            'nouvelleLigneMontantQuantite' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            app(FactureService::class)->ajouterLigneLibreMontant($this->facture, [
                'libelle' => $this->nouvelleLigneMontantLibelle,
                'prix_unitaire' => $prixUnitaire,
                'quantite' => $quantite,
                'sous_categorie_id' => $this->nouvelleLigneMontantSousCategorieId,
                'operation_id' => $this->nouvelleLigneMontantOperationId,
                'seance' => $this->nouvelleLigneMontantSeance !== null ? (int) $this->nouvelleLigneMontantSeance : null,
            ]);

            $this->resetFormLigneMontant();
            $this->afficherFormLigneMontant = false;
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Ligne libre texte ─────────────────────────────────────────────────────

    public function ouvrirFormLigneLibreTexte(): void
    {
        $this->afficherFormLigneTexte = true;
        $this->afficherFormLigneMontant = false;
    }

    public function ajouterLigneLibreTexte(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->validate([
            'nouvelleLigneTexteLibelle' => ['required', 'string', 'max:255'],
        ]);

        try {
            app(FactureService::class)->ajouterLigneLibreTexte($this->facture, $this->nouvelleLigneTexteLibelle);
            $this->resetFormLigneTexte();
            $this->afficherFormLigneTexte = false;
            $this->facture->refresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Mode paiement prévu ───────────────────────────────────────────────────

    public function updatedModePaiementPrevu(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $mode = ModePaiement::tryFrom((string) $this->modePaiementPrevu);
        $this->facture->update(['mode_paiement_prevu' => $mode?->value]);
    }

    /**
     * Quand l'opération du formulaire d'ajout change, la séance précédemment saisie
     * réfère à une plage 1..nombre_seances qui n'est plus valable. Reset à null.
     */
    public function updatedNouvelleLigneMontantOperationId(): void
    {
        $this->nouvelleLigneMontantSeance = null;
    }

    // ── Helpers privés ────────────────────────────────────────────────────────

    private function resetFormLigneMontant(): void
    {
        $this->nouvelleLigneMontantLibelle = '';
        $this->nouvelleLigneMontantPrixUnitaire = '';
        $this->nouvelleLigneMontantQuantite = '1';
        $this->nouvelleLigneMontantSousCategorieId = null;
        $this->nouvelleLigneMontantOperationId = null;
        $this->nouvelleLigneMontantSeance = null;
    }

    private function resetFormLigneTexte(): void
    {
        $this->nouvelleLigneTexteLibelle = '';
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

        $totalLignes = $lignes->whereNotNull('montant')->sum('montant');

        $comptesBancaires = CompteBancaire::saisieManuelle()
            ->orderBy('nom')
            ->get();

        $sousCategoriesRecettes = SousCategorie::whereHas(
            'categorie',
            fn ($q) => $q->where('type', 'recette')
        )->orderBy('nom')->get();

        $operations = \App\Models\Operation::orderBy('nom')->get();

        $aLignesMontantLibre = $lignes->where('type', TypeLigneFacture::MontantLibre)->isNotEmpty();

        return view('livewire.facture-edit', [
            'transactions' => $transactions,
            'selectedIds' => $selectedIds,
            'lignes' => $lignes,
            'totalLignes' => $totalLignes,
            'comptesBancaires' => $comptesBancaires,
            'sousCategoriesRecettes' => $sousCategoriesRecettes,
            'operations' => $operations,
            'aLignesMontantLibre' => $aLignesMontantLibre,
            'modesPaiement' => ModePaiement::cases(),
        ]);
    }
}
