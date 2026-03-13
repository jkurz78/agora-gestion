<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompteBancaireRequest;
use App\Http\Requests\UpdateCompteBancaireRequest;
use App\Models\CompteBancaire;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class CompteBancaireController extends Controller
{
    public function index(): View
    {
        return view('parametres.comptes-bancaires.index', [
            'comptesBancaires' => CompteBancaire::orderBy('nom')->get(),
        ]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('parametres.comptes-bancaires.index');
    }

    public function store(StoreCompteBancaireRequest $request): RedirectResponse
    {
        CompteBancaire::create($request->validated());

        return redirect()->route('parametres.comptes-bancaires.index')
            ->with('success', 'Compte bancaire créé avec succès.');
    }

    public function edit(CompteBancaire $comptesBancaire): RedirectResponse
    {
        return redirect()->route('parametres.comptes-bancaires.index');
    }

    public function update(UpdateCompteBancaireRequest $request, CompteBancaire $comptesBancaire): RedirectResponse
    {
        $comptesBancaire->update($request->validated());

        return redirect()->route('parametres.comptes-bancaires.index')
            ->with('success', 'Compte bancaire mis à jour avec succès.');
    }

    public function destroy(CompteBancaire $comptesBancaire): RedirectResponse
    {
        try {
            $comptesBancaire->delete();

            return redirect()->route('parametres.comptes-bancaires.index')
                ->with('success', 'Compte bancaire supprimé avec succès.');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->route('parametres.comptes-bancaires.index')
                    ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.');
            }
            throw $e;
        }
    }
}
