<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\WithPerPage;
use App\Models\Adhesion;
use App\Models\Tiers;
use App\Services\ExerciceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class AdherentList extends Component
{
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public string $filtre = 'a_jour';

    public string $search = '';

    public function updatedFiltre(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[On('adhesion-creee')]
    public function onAdhesionCreee(): void
    {
        $this->resetPage();
    }

    /**
     * Restreint une requête d'adhésions à celles qui sont « à jour pour l'exercice $annee ».
     *
     * UNE SEULE référence de temps : l'exercice demandé, jamais la date du jour.
     * Une adhésion est à jour pour l'exercice E si :
     *  - son exercice vaut E ; ou
     *  - sa période recouvre E (elle chevauche les bornes de l'exercice) ; ou
     *  - elle est illimitée et a commencé au plus tard à la fin de E.
     *
     * Mélanger l'exercice sélectionné et « aujourd'hui » rendait une liste fausse
     * dans les deux sens : membres de la saison suivante comptés, membre de la
     * saison visée oublié (spec 2026-10-02, D3).
     *
     * @param  Builder<Adhesion>  $adhesions
     */
    private function aJourPourExercice(Builder $adhesions, int $annee): void
    {
        $bornes = app(ExerciceService::class)->dateRange($annee);
        $debut = $bornes['start']->toDateString();
        $fin = $bornes['end']->toDateString();

        $adhesions->where(function (Builder $s) use ($annee, $debut, $fin): void {
            $s->where('exercice', $annee)
                ->orWhere(function (Builder $d) use ($debut, $fin): void {
                    $d->whereNotNull('date_debut')
                        ->whereNotNull('date_fin')
                        ->whereDate('date_debut', '<=', $fin)
                        ->whereDate('date_fin', '>=', $debut);
                })
                ->orWhere(function (Builder $i) use ($fin): void {
                    $i->where('mode', 'illimite')
                        ->where(function (Builder $c) use ($fin): void {
                            $c->whereNull('date_debut')
                                ->orWhereDate('date_debut', '<=', $fin);
                        });
                });
        });
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Tiers::query();

        match ($this->filtre) {
            'a_jour' => $query->whereHas('adhesions', function (Builder $q) use ($exercice): void {
                $this->aJourPourExercice($q, $exercice);
            }),
            // En retard : à jour pour l'exercice précédent, pas pour celui qui est sélectionné.
            'en_retard' => $query
                ->whereHas('adhesions', function (Builder $q) use ($exercice): void {
                    $this->aJourPourExercice($q, $exercice - 1);
                })
                ->whereDoesntHave('adhesions', function (Builder $q) use ($exercice): void {
                    $this->aJourPourExercice($q, $exercice);
                }),
            default => $query->whereHas('adhesions'),
        };

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', '%'.$this->search.'%')
                    ->orWhere('prenom', 'like', '%'.$this->search.'%');
            });
        }

        $membres = $query->orderBy('nom')->paginate($this->effectivePerPage());

        $membres->getCollection()->each(function (Tiers $tiers): void {
            $derniereAdhesion = $tiers->adhesions()
                ->with(['transaction.compte', 'formuleAdhesion'])
                // D'abord la fin de validité : une adhésion illimitée n'en a pas, elle
                // remonte (COALESCE). L'exercice ne passe qu'en second : une adhésion
                // à exercice nul ne doit plus perdre contre une plus ancienne, NULL
                // étant classé en dernier par ORDER BY ... DESC. DATE() normalise le
                // format de stockage ; SQL standard, valable en MySQL et MariaDB.
                ->orderByRaw("COALESCE(DATE(date_fin), '9999-12-31') DESC")
                ->orderByDesc('exercice')
                ->orderByDesc('id')
                ->first();
            $tiers->setAttribute('derniereAdhesion', $derniereAdhesion);
        });

        return view('livewire.adherent-list', compact('membres'));
    }
}
