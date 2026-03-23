<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Services\HelloAssoApiClient;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncConfig extends Component
{
    public ?int $compteHelloassoId = null;

    public ?int $compteVersementId = null;

    public ?int $sousCategorieDonId = null;

    public ?int $sousCategorieCotisationId = null;

    public ?int $sousCategorieInscriptionId = null;

    /** @var array<int, ?int> mapping_id → operation_id */
    public array $formOperations = [];

    public ?string $message = null;

    public ?string $erreur = null;

    public function mount(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p !== null) {
            $this->compteHelloassoId = $p->compte_helloasso_id;
            $this->compteVersementId = $p->compte_versement_id;
            $this->sousCategorieDonId = $p->sous_categorie_don_id;
            $this->sousCategorieCotisationId = $p->sous_categorie_cotisation_id;
            $this->sousCategorieInscriptionId = $p->sous_categorie_inscription_id;

            // Load existing form mappings
            foreach ($p->formMappings as $m) {
                $this->formOperations[$m->id] = $m->operation_id;
            }
        }
    }

    public function sauvegarder(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';

            return;
        }

        $p->update([
            'compte_helloasso_id' => $this->compteHelloassoId ?: null,
            'compte_versement_id' => $this->compteVersementId ?: null,
            'sous_categorie_don_id' => $this->sousCategorieDonId ?: null,
            'sous_categorie_cotisation_id' => $this->sousCategorieCotisationId ?: null,
            'sous_categorie_inscription_id' => $this->sousCategorieInscriptionId ?: null,
        ]);

        $this->message = 'Configuration enregistrée.';
    }

    public function chargerFormulaires(): void
    {
        $this->erreur = null;
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p === null || $p->client_id === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';

            return;
        }

        try {
            $client = new HelloAssoApiClient($p);
            $forms = $client->fetchForms();
        } catch (\RuntimeException $e) {
            $this->erreur = $e->getMessage();

            return;
        }

        // Upsert form mappings
        foreach ($forms as $form) {
            HelloAssoFormMapping::updateOrCreate(
                [
                    'helloasso_parametres_id' => $p->id,
                    'form_slug' => $form['formSlug'],
                ],
                [
                    'form_type' => $form['formType'] ?? '',
                    'form_title' => $form['title'] ?? $form['formSlug'],
                    'start_date' => isset($form['startDate']) ? \Carbon\Carbon::parse($form['startDate'])->toDateString() : null,
                    'end_date' => isset($form['endDate']) ? \Carbon\Carbon::parse($form['endDate'])->toDateString() : null,
                    'state' => $form['state'] ?? null,
                ],
            );
        }

        // Reload mappings
        $this->formOperations = [];
        foreach ($p->formMappings()->get() as $m) {
            $this->formOperations[$m->id] = $m->operation_id;
        }

        $this->message = count($forms).' formulaires chargés.';
    }

    public function sauvegarderFormulaires(): void
    {
        foreach ($this->formOperations as $mappingId => $operationId) {
            HelloAssoFormMapping::where('id', $mappingId)->update([
                'operation_id' => $operationId ?: null,
            ]);
        }

        $this->message = 'Mapping des formulaires enregistré.';
    }

    public function render(): View
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();

        return view('livewire.parametres.helloasso-sync-config', [
            'comptes' => CompteBancaire::orderBy('nom')->get(),
            'sousCategoriesDon' => SousCategorie::where('pour_dons', true)->orderBy('nom')->get(),
            'sousCategoriesCotisation' => SousCategorie::where('pour_cotisations', true)->orderBy('nom')->get(),
            'sousCategoriesInscription' => SousCategorie::where('pour_inscriptions', true)->orderBy('nom')->get(),
            'operations' => Operation::orderBy('nom')->get(),
            'formMappings' => $p?->formMappings()->orderBy('form_slug')->get() ?? collect(),
        ]);
    }
}
