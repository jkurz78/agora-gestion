<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSousCategorieRequest;
use App\Http\Requests\UpdateSousCategorieRequest;
use App\Models\SousCategorie;
use Illuminate\Http\RedirectResponse;

final class SousCategorieController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('parametres.index');
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('parametres.index');
    }

    public function store(StoreSousCategorieRequest $request): RedirectResponse
    {
        SousCategorie::create($request->validated());

        return redirect()->route('parametres.index')
            ->with('success', 'Sous-catégorie créée avec succès.');
    }

    public function edit(SousCategorie $sousCategory): RedirectResponse
    {
        return redirect()->route('parametres.index');
    }

    public function update(UpdateSousCategorieRequest $request, SousCategorie $sousCategory): RedirectResponse
    {
        $sousCategory->update($request->validated());

        return redirect()->route('parametres.index')
            ->with('success', 'Sous-catégorie mise à jour avec succès.');
    }

    public function destroy(SousCategorie $sousCategory): RedirectResponse
    {
        $sousCategory->delete();

        return redirect()->route('parametres.index')
            ->with('success', 'Sous-catégorie supprimée avec succès.');
    }
}
