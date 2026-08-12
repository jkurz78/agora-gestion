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
     * Ouvre le formulaire générique de transaction sur la transaction
     * d'acquisition ou une transaction de dotation de cette fiche — même
     * patron que DotationsExercice::ventiler(). TransactionForm affiche
     * lui-même la transaction en lecture seule (isLockedByImmobilisation) :
     * il n'y a rien d'autre à faire ici que l'ouvrir.
     */
    public function ouvrirTransaction(int $transactionId): void
    {
        $this->authorize('view', $this->immobilisation);

        $this->dispatch('edit-transaction', id: $transactionId);
    }

    public function enregistrerModification(): void
    {
        $this->authorize('update', $this->immobilisation);

        $this->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'quantite' => ['required', 'integer', 'min:1'],
            'date_mise_en_service' => ['required', 'date'],
            'duree_mois' => ['required', 'integer', 'min:1', 'max:600'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'libelle' => 'libellé',
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
