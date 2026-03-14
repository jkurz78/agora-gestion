<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreMembreRequest;
use App\Http\Requests\UpdateMembreRequest;
use App\Models\Tiers;
use App\Services\ExerciceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class MembreController extends Controller
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    public function index(): View
    {
        $exercice = $this->exerciceService->current();

        $membres = Tiers::membres()->with(['cotisations' => function ($query) use ($exercice) {
            $query->forExercice($exercice);
        }])->orderBy('nom')->get();

        return view('membres.index', [
            'membres' => $membres,
            'exercice' => $exercice,
            'exerciceLabel' => $this->exerciceService->label($exercice),
        ]);
    }

    public function create(): View
    {
        return view('membres.create');
    }

    public function store(StoreMembreRequest $request): RedirectResponse
    {
        Tiers::create($request->validated());

        return redirect()->route('membres.index')
            ->with('success', 'Membre ajouté avec succès.');
    }

    public function show(Tiers $membre): View
    {
        $membre->load('cotisations.compte');

        return view('membres.show', [
            'membre' => $membre,
        ]);
    }

    public function edit(Tiers $membre): View
    {
        return view('membres.edit', [
            'membre' => $membre,
        ]);
    }

    public function update(UpdateMembreRequest $request, Tiers $membre): RedirectResponse
    {
        $membre->update($request->validated());

        return redirect()->route('membres.show', $membre)
            ->with('success', 'Membre mis à jour avec succès.');
    }

    public function destroy(Tiers $membre): RedirectResponse
    {
        $membre->delete();

        return redirect()->route('membres.index')
            ->with('success', 'Membre supprimé avec succès.');
    }
}
