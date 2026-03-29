<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ParticipantDonneesMedicales;
use App\Services\FormulaireTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class FormulaireController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->has('token')) {
            return redirect()->route('formulaire.show', ['token' => $request->input('token')]);
        }

        return view('formulaire.index');
    }

    public function show(Request $request): View|RedirectResponse
    {
        $service = app(FormulaireTokenService::class);
        $result = $service->validate($request->input('token', ''));

        if ($result['status'] === 'used') {
            return redirect()->route('formulaire.index')
                ->with('info', 'Ce formulaire a déjà été rempli. Merci.');
        }

        if ($result['status'] !== 'valid') {
            return redirect()->route('formulaire.index')
                ->withErrors(['token' => 'Code invalide ou expiré.']);
        }

        $participant = $result['participant'];
        $participant->load(['tiers', 'operation.typeOperation', 'operation.seances', 'typeOperationTarif', 'donneesMedicales']);

        $operation = $participant->operation;
        $typeOperation = $operation->typeOperation;

        return view('formulaire.remplir', [
            'participant' => $participant,
            'tiers' => $participant->tiers,
            'operation' => $operation,
            'typeOperation' => $typeOperation,
            'tarif' => $participant->typeOperationTarif,
            'donneesMedicales' => $participant->donneesMedicales,
            'seancesCount' => $operation->nombre_seances,
            'token' => $request->input('token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $service = app(FormulaireTokenService::class);
        $result = $service->validate($request->input('token', ''));

        if ($result['status'] !== 'valid') {
            return redirect()->route('formulaire.index')
                ->withErrors(['token' => 'Code invalide ou expiré.']);
        }

        $participant = $result['participant'];

        $request->validate([
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'adresse_ligne1' => ['nullable', 'string', 'max:500'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville' => ['nullable', 'string', 'max:100'],
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'sexe' => ['nullable', 'in:M,F'],
            'taille' => ['nullable', 'numeric', 'between:50,250'],
            'poids' => ['nullable', 'numeric', 'between:20,300'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'documents.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'documents' => ['nullable', 'array', 'max:3'],
        ]);

        $tiers = $participant->tiers;

        // Merge intelligent: only update non-empty changed values
        $coordFields = ['telephone', 'email', 'adresse_ligne1', 'code_postal', 'ville'];
        foreach ($coordFields as $field) {
            $newValue = $request->input($field);
            if ($newValue !== null && $newValue !== '' && $newValue !== ($tiers->{$field} ?? '')) {
                $tiers->{$field} = $newValue;
            }
        }
        $tiers->save();

        // Write medical data
        ParticipantDonneesMedicales::updateOrCreate(
            ['participant_id' => $participant->id],
            [
                'date_naissance' => $request->input('date_naissance') ?: null,
                'sexe' => $request->input('sexe') ?: null,
                'taille' => $request->input('taille') ?: null,
                'poids' => $request->input('poids') ?: null,
                'notes' => $request->input('notes') ?: null,
            ]
        );

        // Store documents
        if ($request->hasFile('documents')) {
            $dir = "participants/{$participant->id}";
            foreach ($request->file('documents') as $file) {
                if ($file->isValid()) {
                    $file->store($dir, 'local');
                }
            }
        }

        // Mark token as used
        $formulaireToken = $participant->formulaireToken;
        $formulaireToken->update([
            'rempli_at' => now(),
            'rempli_ip' => $request->ip(),
        ]);

        return redirect()->route('formulaire.index')
            ->with('success', 'Merci ! Vos informations ont bien été enregistrées. Vous pouvez fermer cette page.');
    }

    public function merci(Request $request): View
    {
        $helloassoUrl = session('helloasso_url');

        return view('formulaire.merci', [
            'helloassoUrl' => $helloassoUrl,
        ]);
    }
}
