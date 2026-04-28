<?php

declare(strict_types=1);

namespace App\Livewire\DevisLibre;

use App\Enums\StatutDevis;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Facture;
use App\Models\SousCategorie;
use App\Services\DevisService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\ComponentSlot;
use Livewire\Component;
use RuntimeException;

final class DevisEdit extends Component
{
    public Devis $devis;

    // ── En-tête editable fields ───────────────────────────────────────────────

    public string $libelle = '';

    public string $dateEmission = '';

    public string $dateValidite = '';

    // ── New ligne form ────────────────────────────────────────────────────────

    public string $nouvelleLigneLibelle = '';

    public string $nouvelleLignePrixUnitaire = '';

    public string $nouvelleLigneQuantite = '1';

    public ?int $nouvelleLigneSousCategorieId = null;

    // ── New ligne texte form ──────────────────────────────────────────────────

    public string $nouveauLigneTexte = '';

    // ── Cached queries (loaded once in mount) ─────────────────────────────────

    /** @var Collection<int, SousCategorie> */
    public $sousCategoriesDisponibles;

    // ── Email modal ───────────────────────────────────────────────────────────

    public bool $showEnvoyerEmailModal = false;

    public string $emailSujet = '';

    public string $emailCorps = '';

    // ── Mount ─────────────────────────────────────────────────────────────────

    public function mount(Devis $devis): void
    {
        $this->devis = $devis;
        $this->libelle = (string) ($devis->libelle ?? '');
        $this->dateEmission = $devis->date_emission->format('Y-m-d');
        $this->dateValidite = $devis->date_validite->format('Y-m-d');
        $this->sousCategoriesDisponibles = SousCategorie::whereHas(
            'categorie',
            fn ($q) => $q->where('type', 'recette')
        )->orderBy('nom')->get();
    }

    // ── Computed helpers ──────────────────────────────────────────────────────

    /**
     * Returns true when the devis can be sent (has at least one ligne with montant > 0).
     */
    public function peutEtreEnvoye(): bool
    {
        $this->devis->load('lignes');

        return $this->devis->lignes->contains(
            fn (DevisLigne $l) => (float) $l->montant > 0.0
        );
    }

    /**
     * Returns true when the devis is in a locked state (Accepte, Refuse, Annule).
     */
    public function estVerrouille(): bool
    {
        return ! $this->devis->statut->peutEtreModifie();
    }

    /**
     * Returns true if the devis is Valide and its date_validite is in the past.
     */
    public function estExpire(): bool
    {
        return $this->devis->statut === StatutDevis::Valide
            && $this->devis->date_validite->lt(today());
    }

    // ── Sauvegarder en-tête ───────────────────────────────────────────────────

    public function sauvegarder(): void
    {
        if ($this->estVerrouille()) {
            return;
        }

        $this->validate([
            'libelle' => 'nullable|string|max:255',
            'dateEmission' => 'required|date',
            'dateValidite' => 'required|date|after_or_equal:dateEmission',
        ]);

        try {
            app(DevisService::class)->sauvegarderEntete($this->devis, [
                'libelle' => $this->libelle,
                'date_emission' => $this->dateEmission,
                'date_validite' => $this->dateValidite,
            ]);

            session()->flash('success', 'Devis enregistré.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Gestion des lignes ────────────────────────────────────────────────────

    public function ajouterLigne(): void
    {
        if ($this->estVerrouille()) {
            session()->flash('error', 'Ce devis est verrouillé et ne peut pas être modifié.');

            return;
        }

        if (trim($this->nouvelleLigneLibelle) === '') {
            session()->flash('error', 'Le libellé de la ligne est requis.');

            return;
        }

        try {
            app(DevisService::class)->ajouterLigne($this->devis, [
                'libelle' => $this->nouvelleLigneLibelle,
                'prix_unitaire' => $this->nouvelleLignePrixUnitaire !== '' ? $this->nouvelleLignePrixUnitaire : '0',
                'quantite' => $this->nouvelleLigneQuantite !== '' ? $this->nouvelleLigneQuantite : '1',
                'sous_categorie_id' => $this->nouvelleLigneSousCategorieId,
            ]);

            $this->nouvelleLigneLibelle = '';
            $this->nouvelleLignePrixUnitaire = '';
            $this->nouvelleLigneQuantite = '1';
            $this->nouvelleLigneSousCategorieId = null;

            $this->devis->refresh();
            session()->flash('success', 'Ligne ajoutée.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function ajouterLigneTexte(): void
    {
        if ($this->estVerrouille()) {
            session()->flash('error', 'Ce devis est verrouillé et ne peut pas être modifié.');

            return;
        }

        if (trim($this->nouveauLigneTexte) === '') {
            session()->flash('error', 'Le texte de la ligne est requis.');

            return;
        }

        try {
            app(DevisService::class)->ajouterLigneTexte($this->devis, $this->nouveauLigneTexte);

            $this->nouveauLigneTexte = '';

            $this->devis->refresh();
            session()->flash('success', 'Ligne texte ajoutée.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function moveUp(int $ligneId): void
    {
        $this->deplacerLigne($ligneId, 'up');
    }

    public function moveDown(int $ligneId): void
    {
        $this->deplacerLigne($ligneId, 'down');
    }

    public function modifierLigneLibelle(int $ligneId, string $libelle): void
    {
        $this->modifierLigne($ligneId, ['libelle' => $libelle]);
    }

    public function modifierLignePrixUnitaire(int $ligneId, string $prixUnitaire): void
    {
        $this->modifierLigne($ligneId, ['prix_unitaire' => $prixUnitaire]);
    }

    public function modifierLigneQuantite(int $ligneId, string $quantite): void
    {
        $this->modifierLigne($ligneId, ['quantite' => $quantite]);
    }

    public function supprimerLigne(int $ligneId): void
    {
        if ($this->estVerrouille()) {
            session()->flash('error', 'Ce devis est verrouillé et ne peut pas être modifié.');

            return;
        }

        $ligne = DevisLigne::find($ligneId);

        if ($ligne === null || (int) $ligne->devis_id !== (int) $this->devis->id) {
            session()->flash('error', 'Ligne introuvable.');

            return;
        }

        try {
            app(DevisService::class)->supprimerLigne($ligne);
            $this->devis->refresh();
            session()->flash('success', 'Ligne supprimée.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Transitions de statut ─────────────────────────────────────────────────

    public function marquerValide(): void
    {
        $this->tenterTransition(
            fn () => app(DevisService::class)->marquerValide($this->devis),
            fn () => 'Devis validé. Numéro attribué : '.$this->devis->numero,
        );
    }

    public function marquerAccepte(): void
    {
        $this->tenterTransition(
            fn () => app(DevisService::class)->marquerAccepte($this->devis),
            'Devis marqué comme accepté.',
        );
    }

    public function marquerRefuse(): void
    {
        $this->tenterTransition(
            fn () => app(DevisService::class)->marquerRefuse($this->devis),
            'Devis marqué comme refusé.',
        );
    }

    public function annuler(): void
    {
        $this->tenterTransition(
            fn () => app(DevisService::class)->annuler($this->devis),
            'Devis annulé.',
        );
    }

    // ── Dupliquer ─────────────────────────────────────────────────────────────

    public function dupliquer(): void
    {
        try {
            $nouveau = app(DevisService::class)->dupliquer($this->devis);
            $this->redirect(route('devis-libres.show', $nouveau));
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Transformer en facture ────────────────────────────────────────────────

    public function transformerEnFacture(): mixed
    {
        if ($this->devis->statut !== StatutDevis::Accepte) {
            session()->flash('error', 'Seul un devis accepté peut être transformé en facture.');

            return null;
        }

        if ($this->devis->aDejaUneFacture()) {
            session()->flash('error', 'Une facture issue de ce devis existe déjà.');

            return null;
        }

        try {
            $facture = app(DevisService::class)->transformerEnFacture($this->devis);

            return $this->redirect(route('facturation.factures.show', $facture), navigate: false);
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return null;
        }
    }

    // ── Email modal ───────────────────────────────────────────────────────────

    public function ouvrirModaleEmail(): void
    {
        $this->showEnvoyerEmailModal = true;
        $this->emailSujet = 'Devis '.(string) ($this->devis->numero ?? 'brouillon');
        $this->emailCorps = '';
    }

    public function envoyerEmail(): void
    {
        try {
            app(DevisService::class)->envoyerEmail(
                $this->devis,
                $this->emailSujet,
                $this->emailCorps,
            );

            $this->showEnvoyerEmailModal = false;
            $this->emailSujet = '';
            $this->emailCorps = '';
            session()->flash('success', 'Email envoyé avec succès.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): View
    {
        $this->devis->load(['lignes', 'tiers', 'accepteParUser', 'refuseParUser', 'annuleParUser']);

        $lignes = $this->devis->lignes;

        return view('livewire.devis-libre.devis-edit', [
            'lignes' => $lignes,
            'sousCategoriesDisponibles' => $this->sousCategoriesDisponibles,
        ])->layout('layouts.app-sidebar', [
            'title' => $this->devis->numero ?? 'Brouillon de devis',
            'breadcrumbParent' => new ComponentSlot('Liste des devis', ['url' => route('devis-libres.index')]),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Exécute une transition de statut, rafraîchit le devis et flashe le message
     * de succès. En cas de RuntimeException, flashe le message d'erreur.
     *
     * $successMessage peut être un string statique ou un callable (pour inclure
     * les données post-refresh comme le numéro).
     *
     * @param  callable(): void  $transition
     * @param  string|callable(): string  $successMessage
     */
    private function tenterTransition(callable $transition, string|callable $successMessage): void
    {
        try {
            $transition();
            $this->devis->refresh();
            $message = is_callable($successMessage) ? $successMessage() : $successMessage;
            session()->flash('success', $message);
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function deplacerLigne(int $ligneId, string $direction): void
    {
        if ($this->estVerrouille()) {
            session()->flash('error', 'Ce devis est verrouillé et ne peut pas être modifié.');

            return;
        }

        try {
            app(DevisService::class)->majOrdre($this->devis, $ligneId, $direction);
            $this->devis->refresh();
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function modifierLigne(int $ligneId, array $data): void
    {
        if ($this->estVerrouille()) {
            session()->flash('error', 'Ce devis est verrouillé et ne peut pas être modifié.');

            return;
        }

        $ligne = DevisLigne::find($ligneId);

        if ($ligne === null || (int) $ligne->devis_id !== (int) $this->devis->id) {
            session()->flash('error', 'Ligne introuvable.');

            return;
        }

        try {
            app(DevisService::class)->modifierLigne($ligne, $data);
            $this->devis->refresh();
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }
}
