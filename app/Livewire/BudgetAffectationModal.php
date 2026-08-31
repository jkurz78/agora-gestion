<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\BudgetLine;
use App\Models\Operation;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\ExerciceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Affectation d'un budget à une opération, en une passe.
 *
 * Le geste réel n'est pas « ventiler un compte » mais « donner son budget à une
 * opération », qui en touche cinq ou six. On choisit l'opération une fois, on
 * saisit tous ses comptes, on enregistre une fois.
 *
 * Une seule propriété tableau et un seul enregistrement transactionnel : une
 * synchronisation live par cellule ferait une centaine d'allers-retours Livewire.
 * Le dépassement (montant saisi vs restant) est recalculé pendant la frappe en
 * JS vanilla, dans la vue — voir le <script> de budget-affectation-modal.blade.php.
 * Le « restant » lui-même reste une valeur serveur figée au dernier rendu ; le
 * serveur recalcule tout à l'enregistrement et reste seul juge.
 */
final class BudgetAffectationModal extends Component
{
    use RespectsExerciceCloture;

    public bool $ouverte = false;

    public ?int $operationId = null;

    public string $filtre = '';

    /** @var array<int, string> compte_id => montant saisi */
    public array $montants = [];

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    #[On('ouvrir-affectation')]
    public function ouvrir(int $operationId): void
    {
        // Le bouton générique de l'écran Budget dispatche 0 (« aucune opération
        // choisie » : le sélecteur de la modale reste vide). Une badge du bandeau
        // « sans budget affecté » dispatche un vrai id.
        $this->operationId = $operationId > 0 ? $operationId : null;
        $this->filtre = '';
        $this->ouverte = true;

        $this->chargerMontants();
    }

    /**
     * Recharge les montants déjà ventilés pour l'opération courante.
     *
     * Appelé par {@see ouvrir()} pour l'ouverture initiale, et par
     * {@see updatedOperationId()} quand l'utilisateur change d'opération via le
     * sélecteur de la modale — sans quoi les cellules resteraient vides pour une
     * opération déjà partiellement budgétée, ce qui masquerait ses montants
     * existants au lieu de les proposer à la modification.
     */
    private function chargerMontants(): void
    {
        if ($this->operationId === null) {
            $this->montants = [];

            return;
        }

        $exercice = app(ExerciceService::class)->current();

        $this->montants = BudgetLine::forExercice($exercice)
            ->where('operation_id', $this->operationId)
            ->get()
            ->mapWithKeys(fn (BudgetLine $l): array => [(int) $l->compte_id => (string) $l->montant_prevu])
            ->all();
    }

    /**
     * Sélection d'une opération via le menu déroulant de la modale (par
     * opposition à {@see ouvrir()}, qui l'ouvre déjà positionnée sur une
     * opération précise). Livewire n'appelle ce hook que pour un changement
     * poussé par le front (wire:model.live), jamais pour l'affectation directe
     * faite dans ouvrir() — les deux chemins restent donc indépendants.
     */
    public function updatedOperationId(): void
    {
        $this->chargerMontants();
    }

    public function fermer(): void
    {
        $this->ouverte = false;
        $this->operationId = null;
        $this->montants = [];
    }

    public function enregistrer(): void
    {
        if (! $this->canEdit || $this->operationId === null || $this->exerciceCloture) {
            return;
        }

        // $this->montants et $this->operationId sont entièrement pilotés par le
        // navigateur : un appel forgé pourrait viser un compte ou une opération
        // d'une autre association. On confronte donc les deux au périmètre
        // réellement affiché — plutôt qu'aux seules règles d'autorisation, qui
        // ne disent rien du tenant.
        if (! Operation::query()->proposableALaSaisie()->whereKey($this->operationId)->exists()) {
            return;
        }

        $comptesAutorises = $this->comptesAutorises();

        $exercice = app(ExerciceService::class)->current();

        // Le gel ne verrouille QUE les enveloppes : aucune garde de validation ici.

        DB::transaction(function () use ($exercice, $comptesAutorises): void {
            foreach ($this->montants as $compteId => $valeur) {
                if (! in_array((int) $compteId, $comptesAutorises, true)) {
                    continue;
                }

                $compteId = (int) $compteId;
                $valeur = trim((string) $valeur);

                $existante = BudgetLine::forExercice($exercice)
                    ->where('compte_id', $compteId)
                    ->where('operation_id', $this->operationId)
                    ->first();

                // Cellule vide ou nulle : on supprime plutôt que de laisser une
                // ligne à zéro, qui polluerait le décompte des opérations budgétées.
                if ($valeur === '' || ! is_numeric($valeur) || (float) $valeur <= 0) {
                    $existante?->delete();

                    continue;
                }

                if ($existante !== null) {
                    $existante->update(['montant_prevu' => (float) $valeur]);

                    continue;
                }

                BudgetLine::create([
                    'compte_id' => $compteId,
                    'operation_id' => $this->operationId,
                    'exercice' => $exercice,
                    'montant_prevu' => (float) $valeur,
                ]);
            }
        });

        $this->dispatch('budget-affecte');
        $this->fermer();
    }

    /**
     * Liste blanche des compte_id que la modale expose réellement — les mêmes
     * comptes que ceux énumérés par {@see lignes()}. Sert de garde tenant pour
     * {@see enregistrer()} : $this->montants est piloté par le navigateur, il
     * ne faut jamais faire confiance à ses clés.
     *
     * @return list<int>
     */
    private function comptesAutorises(): array
    {
        $ids = [];

        foreach (['depense', 'recette'] as $type) {
            foreach (PlanComptableSelecteur::groupesPourType($type) as $groupe) {
                foreach ($groupe['comptes'] as $compte) {
                    $ids[] = (int) $compte->id;
                }
            }
        }

        return $ids;
    }

    public function render(): View
    {
        return view('livewire.budget-affectation-modal', [
            'lignes' => $this->ouverte ? $this->lignes() : [],
            'operations' => $this->ouverte ? Operation::query()->proposableALaSaisie()->orderBy('nom')->get() : collect(),
        ]);
    }

    /**
     * Une entrée par compte de classe 6 et 7, dans l'ordre de l'écran Budget.
     *
     * @return list<array{compte_id: int, numero: string, intitule: string, type: string,
     *                    enveloppe: float|null, restant: float|null, montant: float,
     *                    depassement: float}>
     */
    private function lignes(): array
    {
        $exercice = app(ExerciceService::class)->current();

        $enveloppes = BudgetLine::forExercice($exercice)
            ->enveloppes()
            ->pluck('montant_prevu', 'compte_id')
            ->map(fn ($v): float => (float) $v)
            ->all();

        // Σ ventilations des AUTRES opérations. Inclure celle qu'on édite ferait
        // afficher un restant amputé du montant en cours de modification.
        //
        // Laravel réécrit where(col, '!=', null) en whereNotNull, donc écrire
        // ->where('operation_id', '!=', $this->operationId) sans opération
        // choisie serait correct (redondant avec whereNotNull() de
        // ventilations()) — mais un relecteur pourrait « corriger » ce qui
        // ressemble, en SQL pur, à une comparaison toujours fausse (<> NULL),
        // et casser le comportement. Le when() dit l'intention explicitement :
        // sans opération choisie, TOUTES les ventilations sont déduites.
        $autresVentilations = BudgetLine::forExercice($exercice)
            ->ventilations()
            ->when(
                $this->operationId !== null,
                fn ($q) => $q->where('operation_id', '!=', $this->operationId)
            )
            ->selectRaw('compte_id, SUM(montant_prevu) as total')
            ->groupBy('compte_id')
            ->pluck('total', 'compte_id')
            ->map(fn ($v): float => (float) $v)
            ->all();

        $lignes = [];

        foreach (['depense', 'recette'] as $type) {
            foreach (PlanComptableSelecteur::groupesPourType($type) as $groupe) {
                foreach ($groupe['comptes'] as $compte) {
                    $compteId = (int) $compte->id;

                    if ($this->filtre !== '' && ! str_contains(
                        mb_strtolower($compte->numero_pcg.' '.$compte->intitule),
                        mb_strtolower($this->filtre)
                    )) {
                        continue;
                    }

                    $enveloppe = $enveloppes[$compteId] ?? null;
                    $montant = (float) ($this->montants[$compteId] ?? 0);

                    $restant = $enveloppe === null
                        ? null
                        : round($enveloppe - ($autresVentilations[$compteId] ?? 0.0), 2);

                    // Sans enveloppe, aucun dépassement : sinon toute ventilation
                    // saisie avant le vote de l'AG s'afficherait en rouge.
                    $depassement = ($restant === null || $montant <= $restant)
                        ? 0.0
                        : round($montant - $restant, 2);

                    $lignes[] = [
                        'compte_id' => $compteId,
                        'numero' => $compte->numero_pcg,
                        'intitule' => $compte->intitule,
                        'type' => $type,
                        'enveloppe' => $enveloppe,
                        'restant' => $restant,
                        'montant' => $montant,
                        'depassement' => $depassement,
                    ];
                }
            }
        }

        return $lignes;
    }
}
