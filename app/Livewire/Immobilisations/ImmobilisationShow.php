<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Exceptions\ExerciceCloturedException;
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Exceptions\Immobilisation\SuppressionInterditeException;
use App\Livewire\Immobilisations\Concerns\WithDureeSelector;
use App\Models\Immobilisation;
use App\Models\Transaction;
use App\Services\Immobilisation\ImmobilisationService;
use App\Services\Immobilisation\PlanAmortissementCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class ImmobilisationShow extends Component
{
    use WithDureeSelector;

    public Immobilisation $immobilisation;

    // ── État du formulaire d'édition ─────────────────────────────
    public bool $showEditModal = false;

    public string $libelle = '';

    public int $quantite = 1;

    public int $duree_mois = 60;

    public string $date_mise_en_service = '';

    public string $notes = '';

    public string $flashMessage = '';

    public string $flashType = '';

    /** Transaction dont l'écriture est affichée en lecture seule, si une l'est. */
    public ?int $ecritureTransactionId = null;

    public function mount(Immobilisation $immobilisation): void
    {
        $this->immobilisation = $immobilisation;
    }

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function render(): View
    {
        $this->immobilisation->load(['compte', 'compteAmortissement', 'dotations.transaction', 'transaction.tiers']);

        return view('livewire.immobilisations.immobilisation-show', [
            'plan' => app(PlanAmortissementCalculator::class)->plan($this->immobilisation),
            'dureesUsuelles' => self::DUREES_USUELLES,
            // Toutes les lignes, sans le filtre ventilation() : c'est bien
            // l'écriture complète qu'on montre, contrepartie 401 comprise.
            'ecriture' => $this->ecritureTransactionId === null
                ? null
                : Transaction::with(['lignes.compte', 'lignes.tiers', 'tiers'])
                    ->find($this->ecritureTransactionId),
        ])->layout('layouts.app-sidebar', ['title' => $this->immobilisation->numero]);
    }

    /**
     * Ouvre le formulaire d'édition, pré-rempli avec les valeurs actuelles.
     * `montant_acquisition` et `compte_id` n'y figurent pas : ils sont
     * affichés en lecture seule directement dans la vue.
     */
    public function ouvrirEdition(): void
    {
        $this->authorize('update', $this->immobilisation);

        $this->libelle = $this->immobilisation->libelle;
        $this->quantite = (int) $this->immobilisation->quantite;
        $this->initDureeChoix((int) $this->immobilisation->duree_mois);
        $this->date_mise_en_service = $this->immobilisation->date_mise_en_service->toDateString();
        $this->notes = $this->immobilisation->notes ?? '';
        $this->resetValidation();
        $this->showEditModal = true;
    }

    public function fermerEdition(): void
    {
        $this->showEditModal = false;
    }

    /**
     * Affiche l'écriture d'une transaction de la fiche (acquisition ou
     * dotation) en lecture seule.
     *
     * On n'ouvre plus le formulaire générique de saisie : il charge ses lignes
     * via TransactionLigne::scopeVentilation(), qui filtre `classe IN (6,7)`.
     * Une acquisition se ventilant sur un compte de CLASSE 2, sa seule ligne
     * métier était écartée — d'où un écran sans ligne et un total à 0,00 €. Il
     * affichait par-dessus un compte bancaire vide (normal : la dette 401 ne
     * porte pas de banque, c'est la T2 qui la porte) et un bloc de paiement
     * inopérant, la transaction étant de toute façon refusée à
     * l'enregistrement parce que pilotée par la fiche.
     *
     * Cette vue montre donc l'écriture telle qu'elle est : les lignes réelles
     * du grand livre, débit et crédit, sans prétendre qu'on peut les saisir.
     */
    public function voirEcriture(int $transactionId): void
    {
        $this->authorize('view', $this->immobilisation);

        // Une transaction ne s'affiche depuis cette fiche que si elle lui
        // appartient : transactionId est une propriété publique Livewire,
        // hydratée depuis le client à chaque requête et donc falsifiable.
        if (! $this->appartientALaFiche($transactionId)) {
            abort(403);
        }

        $this->ecritureTransactionId = $transactionId;
    }

    public function fermerEcriture(): void
    {
        $this->ecritureTransactionId = null;
    }

    /**
     * Vrai si la transaction est celle de l'acquisition de cette fiche, ou
     * celle d'une de ses dotations.
     */
    private function appartientALaFiche(int $transactionId): bool
    {
        if ((int) $this->immobilisation->transaction_id === $transactionId) {
            return true;
        }

        return $this->immobilisation->dotations()
            ->where('transaction_id', $transactionId)
            ->exists();
    }

    public function enregistrerModification(): void
    {
        $this->authorize('update', $this->immobilisation);

        $this->validate([
            // Bornes alignées sur les colonnes SQL réelles — voir le
            // commentaire équivalent dans ImmobilisationIndex::enregistrer().
            'libelle' => ['required', 'string', 'max:255'],
            'quantite' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'date_mise_en_service' => ['required', 'date'],
            'duree_mois' => ['required', 'integer', 'min:1', 'max:600'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'libelle' => 'libellé',
            'quantite' => 'quantité',
            'date_mise_en_service' => 'date de mise en service',
            'duree_mois' => 'durée',
        ]);

        try {
            $this->immobilisation = app(ImmobilisationService::class)->modifier(
                immobilisation: $this->immobilisation,
                libelle: $this->libelle,
                quantite: $this->quantite,
                dureeMois: $this->duree_mois,
                dateMiseEnService: CarbonImmutable::parse($this->date_mise_en_service),
                notes: $this->notes === '' ? null : $this->notes,
            );
        } catch (MiseEnServiceAnterieureException $e) {
            $this->addError('date_mise_en_service', $e->getMessage());

            return;
        }

        $this->showEditModal = false;
        $this->flashMessage = 'Immobilisation modifiée.';
        $this->flashType = 'success';
    }

    /**
     * Supprime la fiche et sa transaction d'acquisition, puis redirige vers le
     * livre — la fiche affichée n'existe plus. En cas de refus (dotations déjà
     * générées, exercice clôturé), l'utilisateur reste sur la fiche avec un
     * message d'erreur.
     */
    public function supprimer(): void
    {
        $this->authorize('delete', $this->immobilisation);

        try {
            app(ImmobilisationService::class)->supprimer($this->immobilisation);
        } catch (SuppressionInterditeException|ExerciceCloturedException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'danger';

            return;
        }

        session()->flash('success', 'Immobilisation supprimée, ainsi que son écriture d’acquisition.');
        $this->redirect(route('immobilisations.index'), navigate: false);
    }
}
