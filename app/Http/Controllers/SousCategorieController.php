<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSousCategorieRequest;
use App\Http\Requests\UpdateSousCategorieRequest;
use App\Models\Categorie;
use App\Models\SousCategorie;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SousCategorieController extends Controller
{
    public function index(): View
    {
        return view('parametres.sous-categories.index', [
            'categories' => Categorie::with('sousCategories.categorie')->orderBy('nom')->get(),
            'sousCategories' => SousCategorie::with('categorie')->orderBy('nom')->get(),
        ]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('parametres.sous-categories.index');
    }

    public function store(StoreSousCategorieRequest $request): RedirectResponse
    {
        SousCategorie::create($request->validated());

        return redirect()->route('parametres.sous-categories.index')
            ->with('success', 'Sous-catégorie créée avec succès.');
    }

    public function edit(SousCategorie $sousCategory): RedirectResponse
    {
        return redirect()->route('parametres.sous-categories.index');
    }

    public function update(UpdateSousCategorieRequest $request, SousCategorie $sousCategory): RedirectResponse
    {
        $sousCategory->update($request->validated());

        return redirect()->route('parametres.sous-categories.index')
            ->with('success', 'Sous-catégorie mise à jour avec succès.');
    }

    public function toggleFlag(Request $request, SousCategorie $sousCategory): RedirectResponse
    {
        $flag = $request->input('flag');
        if (! in_array($flag, ['pour_dons', 'pour_cotisations'], true)) {
            abort(422);
        }

        $sousCategory->update([$flag => ! $sousCategory->$flag]);

        return back()->with('success', 'Sous-catégorie mise à jour.');
    }

    public function destroy(SousCategorie $sousCategory): RedirectResponse
    {
        try {
            $sousCategory->delete();

            return redirect()->route('parametres.sous-categories.index')
                ->with('success', 'Sous-catégorie supprimée avec succès.');
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->route('parametres.sous-categories.index')
                    ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.');
            }
            throw $e;
        }
    }
}
