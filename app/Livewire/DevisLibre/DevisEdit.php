<?php

declare(strict_types=1);

namespace App\Livewire\DevisLibre;

use App\Enums\StatutDevis;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\SousCategorie;
use App\Services\DevisService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $this->sousCategoriesDisponibles = SousCategorie::orderBy('nom')->get();
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
     * Returns true if the devis is Envoye and its date_validite is in the past.
     */
    public function estExpire(): bool
    {
        return $this->devis->statut === StatutDevis::Envoye
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

    public function marquerEnvoye(): void
    {
        $this->tenterTransition(
            fn () => app(DevisService::class)->marquerEnvoye($this->devis),
            fn () => 'Devis marqué comme envoyé. Numéro attribué : '.$this->devis->numero,
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

    // ── PDF ───────────────────────────────────────────────────────────────────

    public function telechargerPdf(): ?StreamedResponse
    {
        try {
            $path = app(DevisService::class)->genererPdf($this->devis);

            $filename = basename($path);

            return Storage::disk('local')->download($path, $filename);
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
        ])->layout('layouts.app-sidebar', ['title' => 'Devis libre']);
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
