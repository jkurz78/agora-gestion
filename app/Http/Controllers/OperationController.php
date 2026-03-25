<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StatutOperation;
use App\Http\Requests\StoreOperationRequest;
use App\Http\Requests\UpdateOperationRequest;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Services\ExerciceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class OperationController extends Controller
{
    public function index(Request $request): View
    {
        $showAll = $request->boolean('all');
        $exercice = app(ExerciceService::class)->current();

        $operations = $showAll
            ? Operation::orderByDesc('date_debut')->get()
            : Operation::forExercice($exercice)->orderByDesc('date_debut')->get();

        return view('operations.index', [
            'operations' => $operations,
            'showAll' => $showAll,
            'exercice' => $exercice,
        ]);
    }

    public function create(): View
    {
        return view('operations.create', [
            'sousCategories' => SousCategorie::where('pour_inscriptions', true)->orderBy('nom')->get(),
        ]);
    }

    public function store(StoreOperationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['statut'] = StatutOperation::EnCours;
        Operation::create($data);

        $redirectTo = $request->input('_redirect_back', route('compta.operations.index'));

        return redirect($redirectTo)
            ->with('success', 'Opération créée avec succès.');
    }

    public function show(Operation $operation): View
    {
        $totalDepenses = $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'depense'))
            ->sum('montant');
        $totalRecettes = $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
            ->whereDoesntHave('sousCategorie', fn ($q) => $q->where('pour_dons', true))
            ->sum('montant');
        $totalDons = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
            ->whereHas('sousCategorie', fn ($q) => $q->where('pour_dons', true))
            ->sum('montant');
        $solde = ($totalRecettes + $totalDons) - $totalDepenses;

        return view('operations.show', [
            'operation' => $operation,
            'totalDepenses' => $totalDepenses,
            'totalRecettes' => $totalRecettes,
            'totalDons' => $totalDons,
            'solde' => $solde,
        ]);
    }

    public function edit(Operation $operation): View
    {
        return view('operations.edit', [
            'operation' => $operation,
            'sousCategories' => SousCategorie::where('pour_inscriptions', true)->orderBy('nom')->get(),
        ]);
    }

    public function update(UpdateOperationRequest $request, Operation $operation): RedirectResponse
    {
        $operation->update($request->validated());

        $redirectTo = $request->input('_redirect_back', route('compta.operations.show', $operation));

        return redirect($redirectTo)
            ->with('success', 'Opération mise à jour avec succès.');
    }
}
