<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutOperation;
use App\Enums\UsageComptable;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Reglement;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

final class OperationDetail extends Component
{
    private const ONGLETS = [
        'details', 'participants', 'animateurs', 'seances',
        'reglements', 'questionnaires', 'communication', 'compte_resultat',
        'budget',
    ];

    public Operation $operation;

    #[Url(as: 'tab')]
    public string $activeTab = 'participants';

    public function mount(Operation $operation): void
    {
        $this->operation = $operation->loadMissing('typeOperation.compte');
        if (! in_array($this->activeTab, self::ONGLETS, true)) {
            $this->activeTab = 'participants';
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function toggleStatut(): void
    {
        $this->operation->statut = $this->operation->statut === StatutOperation::EnCours
            ? StatutOperation::Cloturee
            : StatutOperation::EnCours;
        $this->operation->save();
    }

    public function render(): View
    {
        $operation = $this->operation;

        // Financial summary (same logic as old GestionOperations)
        //
        // IMPORTANT — ne pas toucher $totalDepenses (tâche 13, chantier 709A).
        // Une gratuité (709A) n'apparaît QUE côté recette : la synchro HelloAsso
        // ne pose jamais de contre-ligne de gratuité sur une dépense. Appliquer
        // Σcredit − Σdebit ici rendrait les dépenses négatives (une dépense est
        // portée au débit d'un compte de classe 6, donc credit − debit < 0).
        $totalDepenses = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'depense'))
            ->sum('montant');
        // Depuis le chantier 709A, une commande remisée pose DEUX lignes de
        // classe 7 : le produit brut au crédit (706A/751) et la gratuité au
        // débit (709A). `montant` porte la même valeur absolue sur les deux —
        // les sommer naïvement double-compte (100 € au lieu de 0 pour une
        // inscription à 50 € entièrement offerte). Pour toute ligne rattachée
        // à un compte de classe 6/7, on prend donc le net crédit − débit ; les
        // lignes sans compte lié (legacy, pas encore compte-first) gardent
        // `montant` pour ne rien changer à leur comportement historique.
        $totalRecettes = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
            ->whereDoesntHave('compte.usages', fn ($q) => $q->where('usage', UsageComptable::Don->value))
            ->leftJoin('comptes as tl_cpt', 'tl_cpt.id', '=', 'transaction_lignes.compte_id')
            ->sum(DB::raw('CASE WHEN tl_cpt.classe IN (6, 7) THEN (transaction_lignes.credit - transaction_lignes.debit) ELSE transaction_lignes.montant END'));
        $totalDons = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
            ->whereHas('compte.usages', fn ($q) => $q->where('usage', UsageComptable::Don->value))
            ->sum('montant');
        $solde = ($totalRecettes + $totalDons) - $totalDepenses;

        $totalDepensesPrev = (float) EncadrementPrevision::where('operation_id', $operation->id)
            ->sum('montant_prevu');

        $totalRecettesPrev = (float) Reglement::query()
            ->whereIn('participant_id', $operation->participants()->pluck('id'))
            ->sum('montant_prevu');

        $soldePrev = $totalRecettesPrev - $totalDepensesPrev;
        $ecartDepenses = $totalDepenses - $totalDepensesPrev;
        $ecartRecettes = $totalRecettes - $totalRecettesPrev;
        $ecartSolde = $solde - $soldePrev;

        $participantsCount = $operation->participants()->count();
        $seancesCount = $operation->seances()->count();

        // Build metadata string for breadcrumb
        $dateParts = [];
        if ($operation->date_debut) {
            $dateParts[] = $operation->date_debut->format('d/m');
        }
        if ($operation->date_fin) {
            $dateParts[] = $operation->date_fin->format('d/m');
        }
        $operationMeta = implode(' — ', $dateParts);
        if ($participantsCount > 0) {
            $operationMeta .= " · {$participantsCount} participant".($participantsCount > 1 ? 's' : '');
        }
        if ($seancesCount > 0) {
            $operationMeta .= " · {$seancesCount} séance".($seancesCount > 1 ? 's' : '');
        }

        return view('livewire.operation-detail', [
            'totalDepenses' => $totalDepenses,
            'totalRecettes' => $totalRecettes,
            'totalDons' => $totalDons,
            'solde' => $solde,
            'totalDepensesPrev' => $totalDepensesPrev,
            'totalRecettesPrev' => $totalRecettesPrev,
            'soldePrev' => $soldePrev,
            'ecartDepenses' => $ecartDepenses,
            'ecartRecettes' => $ecartRecettes,
            'ecartSolde' => $ecartSolde,
            'participantsCount' => $participantsCount,
            'operationMeta' => $operationMeta,
        ]);
    }
}
