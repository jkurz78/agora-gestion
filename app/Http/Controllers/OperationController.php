<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StatutOperation;
use App\Http\Requests\StoreOperationRequest;
use App\Http\Requests\UpdateOperationRequest;
use App\Models\Operation;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class OperationController extends Controller
{
    public function index(): View
    {
        $operations = Operation::orderByDesc('date_debut')->get();

        return view('operations.index', [
            'operations' => $operations,
        ]);
    }

    public function create(): View
    {
        return view('operations.create');
    }

    public function store(StoreOperationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['statut'] = StatutOperation::EnCours;
        Operation::create($data);

        $redirectTo = $request->input('_redirect_back', route('operations.index'));

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
            ->sum('montant');
        $totalDons = $operation->dons()->sum('montant');
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
        ]);
    }

    public function update(UpdateOperationRequest $request, Operation $operation): RedirectResponse
    {
        $operation->update($request->validated());

        return redirect()->route('operations.show', $operation)
            ->with('success', 'Opération mise à jour avec succès.');
    }
}
