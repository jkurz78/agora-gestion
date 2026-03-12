<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompteBancaireRequest;
use App\Http\Requests\UpdateCompteBancaireRequest;
use App\Models\CompteBancaire;
use Illuminate\Http\RedirectResponse;

final class CompteBancaireController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('parametres.index');
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('parametres.index');
    }

    public function store(StoreCompteBancaireRequest $request): RedirectResponse
    {
        CompteBancaire::create($request->validated());

        return redirect()->route('parametres.index')
            ->with('success', 'Compte bancaire créé avec succès.');
    }

    public function edit(CompteBancaire $comptesBancaire): RedirectResponse
    {
        return redirect()->route('parametres.index');
    }

    public function update(UpdateCompteBancaireRequest $request, CompteBancaire $comptesBancaire): RedirectResponse
    {
        $comptesBancaire->update($request->validated());

        return redirect()->route('parametres.index')
            ->with('success', 'Compte bancaire mis à jour avec succès.');
    }

    public function destroy(CompteBancaire $comptesBancaire): RedirectResponse
    {
        $comptesBancaire->delete();

        return redirect()->route('parametres.index')
            ->with('success', 'Compte bancaire supprimé avec succès.');
    }
}
