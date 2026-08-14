<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\DotationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class DotationsExercice extends Component
{
    public int $exercice = 0;

    public string $flashMessage = '';

    public string $flashType = '';

    public function mount(): void
    {
        // Par défaut, l'exercice précédent : c'est celui qu'on clôture.
        $defaut = app(ExerciceService::class)->current() - 1;

        // …sauf s'il n'est pas générable (déjà clôturé, ou pas encore
        // commencé) : l'écran s'ouvrirait alors sur une année absente de son
        // propre sélecteur, qui afficherait la première option pendant que le
        // composant travaille sur une autre. On retombe sur le plus récent des
        // exercices réellement générables.
        $generables = app(DotationService::class)->exercicesGenerables();

        $this->exercice = in_array($defaut, $generables, true)
            ? $defaut
            : ($generables[0] ?? $defaut);
    }

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function render(): View
    {
        return view('livewire.immobilisations.dotations-exercice', [
            'lignes' => app(DotationService::class)->apercu($this->exercice),
            'exerciceService' => app(ExerciceService::class),
            // Dérivé de la règle du service (voir exercicesGenerables()) et non
            // d'une plage d'années civiles : chaque choix offert doit pouvoir
            // aboutir.
            'exercicesDisponibles' => app(DotationService::class)->exercicesGenerables(),
        ])->layout('layouts.app-sidebar', ['title' => 'Dotations aux amortissements']);
    }

    public function genererTout(): void
    {
        $this->authorize('create', Immobilisation::class);

        try {
            $nombre = app(DotationService::class)->generer($this->exercice);
        } catch (DotationInterditeException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'warning';

            return;
        }

        $this->flashMessage = $nombre === 0
            ? 'Aucune dotation à générer pour cet exercice.'
            : $nombre.' dotation'.($nombre > 1 ? 's générées' : ' générée').'. Pensez à les ventiler avant de clôturer.';
        $this->flashType = 'success';
    }

    public function recalculer(int $immobilisationId): void
    {
        $immobilisation = Immobilisation::findOrFail($immobilisationId);
        $this->authorize('update', $immobilisation);

        try {
            app(DotationService::class)->recalculer($immobilisation, $this->exercice);
        } catch (DotationInterditeException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'warning';

            return;
        }

        $this->flashMessage = 'Dotation recalculée pour '.$immobilisation->numero
            .'. Si elle avait été ventilée, la ventilation est à refaire.';
        $this->flashType = 'success';
    }

    /**
     * Ouvre le formulaire générique de transaction sur la dotation déjà
     * comptabilisée, par-dessus l'écran de clôture — le comptable ventile
     * sans quitter des yeux son travail de fin d'exercice.
     */
    public function ventiler(int $transactionId): void
    {
        $this->authorize('create', Immobilisation::class);

        $this->dispatch('edit-transaction', id: $transactionId);
    }

    /**
     * L'action est bien une suppression, et l'IHM le dit ainsi : « annuler »
     * désigne partout ailleurs dans l'application l'extourne d'une écriture —
     * une opération comptable tracée qui laisse la pièce d'origine en place
     * (TransactionService::annuler()). Ici la dotation et son écriture
     * disparaissent. Deux sens opposés sous le même mot, sur un bouton rouge :
     * le vocabulaire de l'écran suit ce qui se passe réellement.
     */
    public function supprimerDotation(int $immobilisationId): void
    {
        $immobilisation = Immobilisation::findOrFail($immobilisationId);
        $this->authorize('update', $immobilisation);

        try {
            app(DotationService::class)->annuler($immobilisation, $this->exercice);
        } catch (DotationInterditeException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'warning';

            return;
        }

        $this->flashMessage = 'Dotation supprimée pour '.$immobilisation->numero
            .'. Elle peut être regénérée depuis cet écran.';
        $this->flashType = 'success';
    }
}
