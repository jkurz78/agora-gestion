<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutOperation;
use App\Enums\UsageComptable;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Famille;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\BudgetService;
use App\Services\ExerciceService;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\SoldeService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Component;

final class Dashboard extends Component
{
    use RespectsExerciceCloture;

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $budgetService = app(BudgetService::class);
        $exercice = $exerciceService->current();

        $range = $exerciceService->dateRange($exercice);
        $startDate = $range['start']->toDateString();
        $endDate = $range['end']->toDateString();

        // Solde général — lu sur le grand livre (classes 6 et 7), pas sur
        // montant_total filtré par journal : ce dernier comptait l'acquisition
        // d'une immobilisation comme une charge (journal `achat`, alors que la
        // ventilation est en classe 2, un actif) et ignorait la dotation aux
        // amortissements qui en est la vraie charge (journal `od`).
        // Voir CompteResultatBuilder::totauxResultat().
        $totaux = app(CompteResultatBuilder::class)->totauxResultat($startDate, $endDate);
        $totalRecettes = $totaux['produits'];
        $totalDepenses = $totaux['charges'];
        $soldeGeneral = round($totalRecettes - $totalDepenses, 2);

        // Budget résumé — agrégation par famille (lecture compte-first)
        $budgetLines = BudgetLine::forExercice($exercice)
            ->enveloppes()
            ->with(['compte'])
            ->get();

        $familles = Famille::pourComptes(EloquentCollection::make($budgetLines->pluck('compte')->filter()));

        // Prévu — uniquement les comptes budgétés : une enveloppe n'existe que
        // là où elle a été saisie, par définition.
        $recettesPrevu = 0.0;
        $depensesPrevu = 0.0;
        $realiseMap = $budgetService->realiseParCompte($exercice);
        $budgetParFamille = []; // ['nomGroupe' => ['type' => 'depense|recette', 'prevu' => float, 'realise' => float]]
        foreach ($budgetLines as $line) {
            $r = $line->compte_id !== null ? ($realiseMap[(int) $line->compte_id] ?? 0.0) : 0.0;

            $compte = $line->compte;
            $classe = $compte?->classe;
            if ($classe === 7) {
                $recettesPrevu += (float) $line->montant_prevu;
            } elseif ($classe === 6) {
                $depensesPrevu += (float) $line->montant_prevu;
            }

            $famille = $compte !== null ? $familles->get(substr($compte->numero_pcg, 0, 2)) : null;

            $familleKey = $famille?->nom ?? '—';
            if (! isset($budgetParFamille[$familleKey])) {
                $budgetParFamille[$familleKey] = [
                    'type' => $famille !== null ? ($famille->estDepense() ? 'depense' : 'recette') : 'depense',
                    'prevu' => 0.0,
                    'realise' => 0.0,
                ];
            }
            $budgetParFamille[$familleKey]['prevu'] += (float) $line->montant_prevu;
            $budgetParFamille[$familleKey]['realise'] += $r;
        }

        // Réalisé — TOUS les comptes de classe 6 et 7, budgétés ou non. Une
        // enveloppe n'est qu'un prévu ; elle ne doit jamais borner le périmètre
        // du réalisé. En bouclant sur $budgetLines comme le prévu ci-dessus, un
        // compte mouvementé sans enveloppe disparaissait silencieusement du
        // résultat de la tuile sans disparaître du « Solde général » calculé
        // plus haut dans cette même méthode — deux chiffres prétendant tous
        // deux être le résultat de l'exercice. Le contrôle : resultatRealise
        // doit toujours égaler $soldeGeneral.
        $recettesRealise = 0.0;
        $depensesRealise = 0.0;
        $comptesResultat = Compte::query()->whereIn('classe', [6, 7])->get(['id', 'classe']);
        foreach ($comptesResultat as $compte) {
            $r = $realiseMap[(int) $compte->id] ?? 0.0;
            if ($compte->classe === 7) {
                $recettesRealise += $r;
            } else {
                $depensesRealise += $r;
            }
        }

        $resultatPrevu = $recettesPrevu - $depensesPrevu;
        $resultatRealise = $recettesRealise - $depensesRealise;

        // Tri : recettes en premier, puis dépenses, alpha dans chaque groupe
        uksort($budgetParFamille, function ($a, $b) use ($budgetParFamille): int {
            $ta = $budgetParFamille[$a]['type'] === 'recette' ? 0 : 1;
            $tb = $budgetParFamille[$b]['type'] === 'recette' ? 0 : 1;

            return $ta <=> $tb ?: strcasecmp($a, $b);
        });

        // Dernières dépenses (avec tiers)
        $dernieresDepenses = Transaction::where('type', 'depense')->operationnel()->forExercice($exercice)
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Dernières recettes (avec tiers)
        $dernieresRecettes = Transaction::where('type', 'recette')->operationnel()->forExercice($exercice)
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Derniers dons — lignes de transaction avec compte usage Don
        // (et non transactions entières : une même transaction peut contenir
        // une adhésion ET un don, il faut isoler chaque ligne par montant).
        $donCompteIds = Compte::forUsage(UsageComptable::Don)->pluck('id');
        $derniersDons = TransactionLigne::query()
            ->whereIn('transaction_lignes.compte_id', $donCompteIds)
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette')->forExercice($exercice))
            ->with(['transaction.tiers', 'compte'])
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->orderByDesc('transactions.date')
            ->orderByDesc('transaction_lignes.id')
            ->select('transaction_lignes.*')
            ->take(5)
            ->get();

        // Dernières adhésions — lignes de cotisation, avec adhésion (formule + dates)
        $cotCompteIds = Compte::forUsage(UsageComptable::Cotisation)->pluck('id');
        $dernieresAdhesions = TransactionLigne::query()
            ->whereIn('transaction_lignes.compte_id', $cotCompteIds)
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette')->forExercice($exercice))
            ->with(['transaction.tiers', 'transaction.adhesions.formuleAdhesion', 'compte'])
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->orderByDesc('transactions.date')
            ->orderByDesc('transaction_lignes.id')
            ->select('transaction_lignes.*')
            ->take(5)
            ->get();

        // Opérations de l'exercice (hors terminées)
        $range = $exerciceService->dateRange($exercice);
        $operations = Operation::query()
            ->with(['typeOperation.compte'])
            ->withCount('participants')
            ->where('statut', '!=', StatutOperation::Cloturee)
            ->where(function ($q) use ($range): void {
                $q->where(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNotNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_fin', '>=', $range['start']->toDateString());
                })->orWhere(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_debut', '>=', $range['start']->toDateString());
                });
            })
            ->get()
            ->sortBy([
                fn ($a, $b) => ($a->typeOperation?->compte?->intitule ?? '') <=> ($b->typeOperation?->compte?->intitule ?? ''),
                fn ($a, $b) => ($a->typeOperation?->nom ?? '') <=> ($b->typeOperation?->nom ?? ''),
                fn ($a, $b) => $a->nom <=> $b->nom,
            ])->values();

        // Comptes bancaires avec soldes courants
        $soldeService = app(SoldeService::class);
        $comptesAvecSolde = CompteBancaire::orderBy('nom')->get()
            ->map(fn (CompteBancaire $c) => [
                'compte' => $c,
                'solde' => $soldeService->solde($c),
            ]);

        return view('livewire.dashboard', [
            'soldeGeneral' => $soldeGeneral,
            'totalRecettes' => $totalRecettes,
            'totalDepenses' => $totalDepenses,
            'recettesPrevu' => round($recettesPrevu, 2),
            'recettesRealise' => round($recettesRealise, 2),
            'depensesPrevu' => round($depensesPrevu, 2),
            'depensesRealise' => round($depensesRealise, 2),
            'resultatPrevu' => round($resultatPrevu, 2),
            'resultatRealise' => round($resultatRealise, 2),
            'budgetParFamille' => $budgetParFamille,
            'dernieresDepenses' => $dernieresDepenses,
            'dernieresRecettes' => $dernieresRecettes,
            'derniersDons' => $derniersDons,
            'dernieresAdhesions' => $dernieresAdhesions,
            'operations' => $operations,
            'comptesAvecSolde' => $comptesAvecSolde,
        ]);
    }
}
