<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompteBancaireRequest;
use App\Http\Requests\UpdateCompteBancaireRequest;
use App\Models\CompteBancaire;
use App\Models\VirementInterne;
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
        // Vérifier les transactions actives et archivées (soft-deleted)
        $actives = $comptesBancaire->transactions()->count()
            + VirementInterne::where('compte_source_id', $comptesBancaire->id)
                ->orWhere('compte_destination_id', $comptesBancaire->id)
                ->count();

        $archivees = $comptesBancaire->transactions()->onlyTrashed()->count()
            + VirementInterne::onlyTrashed()
                ->where(fn ($q) => $q->where('compte_source_id', $comptesBancaire->id)
                    ->orWhere('compte_destination_id', $comptesBancaire->id))
                ->count();

        if ($actives > 0) {
            return redirect()->route('parametres.comptes-bancaires.index')
                ->with('error', "Suppression impossible : ce compte a {$actives} transaction(s) active(s).");
        }

        if ($archivees > 0) {
            return redirect()->route('parametres.comptes-bancaires.index')
                ->with('error', "Suppression impossible : ce compte a {$archivees} transaction(s) archivée(s) (supprimées mais conservées pour la traçabilité). Purgez-les d'abord si nécessaire.");
        }

        $comptesBancaire->delete();

        return redirect()->route('parametres.comptes-bancaires.index')
            ->with('success', 'Compte bancaire supprimé avec succès.');
    }
}
