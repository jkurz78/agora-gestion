<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategorieRequest;
use App\Http\Requests\UpdateCategorieRequest;
use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;

final class CategorieController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('parametres.categories.index');
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('parametres.categories.index');
    }

    public function store(StoreCategorieRequest $request): RedirectResponse
    {
        Categorie::create($request->validated());

        return redirect()->route('parametres.categories.index')
            ->with('success', 'Catégorie créée avec succès.');
    }

    public function edit(Categorie $category): RedirectResponse
    {
        return redirect()->route('parametres.categories.index');
    }

    public function update(UpdateCategorieRequest $request, Categorie $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()->route('parametres.categories.index')
            ->with('success', 'Catégorie mise à jour avec succès.');
    }

    public function destroy(Categorie $category): RedirectResponse
    {
        try {
            $category->delete();

            return redirect()->route('parametres.categories.index')
                ->with('success', 'Catégorie supprimée avec succès.');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->route('parametres.categories.index')
                    ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.');
            }
            throw $e;
        }
    }
}
