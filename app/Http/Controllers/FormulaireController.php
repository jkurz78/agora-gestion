<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Models\Exercice;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Reglement;
use App\Models\Seance;
use App\Services\FormulaireTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $participant->load(['tiers', 'operation.typeOperation', 'operation.seances', 'typeOperationTarif', 'donneesMedicales', 'referePar']);

        $operation = $participant->operation;
        $typeOperation = $operation->typeOperation;

        // Pre-fill adresse_par from referePar if text fields are empty
        if (!$participant->adresse_par_nom && $participant->referePar) {
            $ref = $participant->referePar;
            $participant->adresse_par_nom = $ref->nom;
            $participant->adresse_par_prenom = $ref->prenom;
            $participant->adresse_par_telephone = $ref->telephone;
            $participant->adresse_par_email = $ref->email;
            $participant->adresse_par_adresse = $ref->adresse_ligne1;
            $participant->adresse_par_code_postal = $ref->code_postal;
            $participant->adresse_par_ville = $ref->ville;
            $participant->adresse_par_etablissement = $ref->entreprise;
        }

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
        $participant->load('operation.typeOperation');
        $typeOperation = $participant->operation->typeOperation;
        $isParcours = $typeOperation?->formulaire_parcours_therapeutique ?? false;
        $needsToken = $isParcours || ($typeOperation?->formulaire_droit_image ?? false);

        $request->validate([
            // Coordonnées
            'tiers_nom' => ['nullable', 'string', 'max:255'],
            'tiers_prenom' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'adresse_ligne1' => ['nullable', 'string', 'max:500'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville' => ['nullable', 'string', 'max:100'],
            'nom_jeune_fille' => ['nullable', 'string', 'max:255'],
            'nationalite' => ['nullable', 'string', 'max:100'],
            // Adressé par
            'adresse_par_nom' => ['nullable', 'string', 'max:255'],
            'adresse_par_prenom' => ['nullable', 'string', 'max:255'],
            'adresse_par_etablissement' => ['nullable', 'string', 'max:255'],
            'adresse_par_telephone' => ['nullable', 'string', 'max:30'],
            'adresse_par_email' => ['nullable', 'email', 'max:255'],
            'adresse_par_adresse' => ['nullable', 'string', 'max:500'],
            'adresse_par_code_postal' => ['nullable', 'string', 'max:10'],
            'adresse_par_ville' => ['nullable', 'string', 'max:100'],
            // Santé
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'sexe' => ['nullable', 'in:M,F'],
            'taille' => ['nullable', 'numeric', 'between:50,250'],
            'poids' => ['nullable', 'numeric', 'between:20,300'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'medecin_nom' => ['nullable', 'string', 'max:255'],
            'medecin_prenom' => ['nullable', 'string', 'max:255'],
            'medecin_telephone' => ['nullable', 'string', 'max:30'],
            'medecin_email' => ['nullable', 'email', 'max:255'],
            'medecin_adresse' => ['nullable', 'string', 'max:500'],
            'medecin_code_postal' => ['nullable', 'string', 'max:10'],
            'medecin_ville' => ['nullable', 'string', 'max:100'],
            'therapeute_nom' => ['nullable', 'string', 'max:255'],
            'therapeute_prenom' => ['nullable', 'string', 'max:255'],
            'therapeute_telephone' => ['nullable', 'string', 'max:30'],
            'therapeute_email' => ['nullable', 'email', 'max:255'],
            'therapeute_adresse' => ['nullable', 'string', 'max:500'],
            'therapeute_code_postal' => ['nullable', 'string', 'max:10'],
            'therapeute_ville' => ['nullable', 'string', 'max:100'],
            // Documents
            'documents.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'documents' => ['nullable', 'array', 'max:3'],
            // Engagement financier
            'mode_paiement_choisi' => ['nullable', 'in:comptant,par_seance'],
            'moyen_paiement_choisi' => ['nullable', 'in:especes,cheque,virement'],
            // Droit à l'image
            'droit_image' => ['nullable', 'in:usage_propre,usage_confidentiel,diffusion,refus'],
            // Engagements
            'engagement_presence' => $isParcours ? ['required', 'accepted'] : ['nullable'],
            'engagement_certificat' => $isParcours ? ['required', 'accepted'] : ['nullable'],
            'engagement_reglement' => ($isParcours && $participant->typeOperationTarif && (float) $participant->typeOperationTarif->montant > 0)
                ? ['required', 'accepted']
                : ['nullable'],
            'engagement_rgpd' => ['required', 'accepted'],
            'autorisation_contact_medecin' => ['nullable'],
            // Confirmation token
            'token_confirmation' => $needsToken ? ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                $normalized = strtoupper(str_replace(' ', '', $value));
                $expected = strtoupper(str_replace(' ', '', $request->input('token', '')));
                if ($normalized !== $expected) {
                    $fail('Le code de confirmation ne correspond pas.');
                }
            }] : ['nullable'],
        ]);

        DB::transaction(function () use ($request, $participant): void {
            // 1. Merge Tiers
            $tiers = $participant->tiers;
            // Nom/prénom si collectés (champs conditionnels)
            if ($request->filled('tiers_nom')) {
                $tiers->nom = $request->input('tiers_nom');
            }
            if ($request->filled('tiers_prenom')) {
                $tiers->prenom = $request->input('tiers_prenom');
            }
            $coordFields = ['telephone', 'email', 'adresse_ligne1', 'code_postal', 'ville'];
            foreach ($coordFields as $field) {
                $newValue = $request->input($field);
                if ($newValue !== null && $newValue !== '' && $newValue !== ($tiers->{$field} ?? '')) {
                    $tiers->{$field} = $newValue;
                }
            }
            $tiers->save();

            // 2. Update Participant
            $participant->update([
                'nom_jeune_fille' => $request->input('nom_jeune_fille') ?: null,
                'nationalite' => $request->input('nationalite') ?: null,
                'adresse_par_nom' => $request->input('adresse_par_nom') ?: null,
                'adresse_par_prenom' => $request->input('adresse_par_prenom') ?: null,
                'adresse_par_etablissement' => $request->input('adresse_par_etablissement') ?: null,
                'adresse_par_telephone' => $request->input('adresse_par_telephone') ?: null,
                'adresse_par_email' => $request->input('adresse_par_email') ?: null,
                'adresse_par_adresse' => $request->input('adresse_par_adresse') ?: null,
                'adresse_par_code_postal' => $request->input('adresse_par_code_postal') ?: null,
                'adresse_par_ville' => $request->input('adresse_par_ville') ?: null,
                'droit_image' => $request->input('droit_image') ?: null,
                'mode_paiement_choisi' => $request->input('mode_paiement_choisi') ?: null,
                'moyen_paiement_choisi' => $request->input('moyen_paiement_choisi') ?: null,
                'autorisation_contact_medecin' => $request->boolean('autorisation_contact_medecin'),
                'rgpd_accepte_at' => now(),
            ]);

            // 3. Upsert medical data
            ParticipantDonneesMedicales::updateOrCreate(
                ['participant_id' => $participant->id],
                [
                    'date_naissance' => $request->input('date_naissance') ?: null,
                    'sexe' => $request->input('sexe') ?: null,
                    'taille' => $request->input('taille') ?: null,
                    'poids' => $request->input('poids') ?: null,
                    'notes' => $request->input('notes') ?: null,
                    'medecin_nom' => $request->input('medecin_nom') ?: null,
                    'medecin_prenom' => $request->input('medecin_prenom') ?: null,
                    'medecin_telephone' => $request->input('medecin_telephone') ?: null,
                    'medecin_email' => $request->input('medecin_email') ?: null,
                    'medecin_adresse' => $request->input('medecin_adresse') ?: null,
                    'medecin_code_postal' => $request->input('medecin_code_postal') ?: null,
                    'medecin_ville' => $request->input('medecin_ville') ?: null,
                    'therapeute_nom' => $request->input('therapeute_nom') ?: null,
                    'therapeute_prenom' => $request->input('therapeute_prenom') ?: null,
                    'therapeute_telephone' => $request->input('therapeute_telephone') ?: null,
                    'therapeute_email' => $request->input('therapeute_email') ?: null,
                    'therapeute_adresse' => $request->input('therapeute_adresse') ?: null,
                    'therapeute_code_postal' => $request->input('therapeute_code_postal') ?: null,
                    'therapeute_ville' => $request->input('therapeute_ville') ?: null,
                ]
            );

            // 4. Store documents
            if ($request->hasFile('documents')) {
                $dir = "participants/{$participant->id}";
                foreach ($request->file('documents') as $file) {
                    if ($file->isValid()) {
                        $file->store($dir, 'local');
                    }
                }
            }

            // 5. Create reglements if none exist
            $this->createReglementsIfNeeded($participant, $request);

            // 6. Mark token
            $participant->formulaireToken->update([
                'rempli_at' => now(),
                'rempli_ip' => $request->ip(),
            ]);
        });

        // Resolve HelloAsso URL via flash session (not query param — avoids URL guessing)
        $helloassoUrl = null;
        $typeOperation = $participant->operation->typeOperation;
        if ($typeOperation?->reserve_adherents) {
            $now = now();
            $annee = $now->month >= 9 ? $now->year : $now->year - 1;
            $exercice = Exercice::where('annee', $annee)->first();
            $helloassoUrl = $exercice?->helloasso_url;
        }

        return redirect()->route('formulaire.merci')
            ->with('helloasso_url', $helloassoUrl);
    }

    private function createReglementsIfNeeded(Participant $participant, Request $request): void
    {
        if (Reglement::where('participant_id', $participant->id)->exists()) {
            return;
        }

        $tarif = $participant->typeOperationTarif;
        if ($tarif === null) {
            return;
        }

        $seances = Seance::where('operation_id', $participant->operation_id)
            ->orderBy('numero')
            ->get();

        if ($seances->isEmpty()) {
            return;
        }

        $moyenMap = [
            'especes' => ModePaiement::Especes,
            'cheque' => ModePaiement::Cheque,
            'virement' => ModePaiement::Virement,
        ];
        $moyen = $moyenMap[$request->input('moyen_paiement_choisi')] ?? null;

        // Note : comptant et par_seance produisent le même montant par séance
        // car total = nb_seances * tarif et montant_ligne = total / nb_seances = tarif.
        // Le choix est stocké sur Participant.mode_paiement_choisi comme préférence.
        foreach ($seances as $seance) {
            Reglement::create([
                'participant_id' => $participant->id,
                'seance_id' => $seance->id,
                'mode_paiement' => $moyen,
                'montant_prevu' => $tarif->montant,
            ]);
        }
    }

    public function merci(Request $request): View
    {
        $helloassoUrl = session('helloasso_url');

        return view('formulaire.merci', [
            'helloassoUrl' => $helloassoUrl,
        ]);
    }
}
