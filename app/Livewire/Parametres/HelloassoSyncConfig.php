<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Enums\UsageComptable;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncConfig extends Component
{
    public ?int $compteHelloassoId = null;

    public ?int $compteVersementId = null;

    public ?int $sousCategorieDonId = null;

    public ?int $sousCategorieCotisationId = null;

    public ?int $sousCategorieInscriptionId = null;

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

        $this->dispatch('form-saved');
        $this->message = 'Configuration enregistrée.';
    }

    public function render(): View
    {
        return view('livewire.parametres.helloasso-sync-config', [
            'comptesHelloasso' => CompteBancaire::where('actif_recettes_depenses', true)
                ->where('saisie_automatisee', true)
                ->orderBy('nom')
                ->get(),
            'comptesVersement' => CompteBancaire::saisieManuelle()->orderBy('nom')->get(),
            'sousCategoriesDon' => SousCategorie::forUsage(UsageComptable::Don)->orderBy('nom')->get(),
            'sousCategoriesCotisation' => SousCategorie::forUsage(UsageComptable::Cotisation)->orderBy('nom')->get(),
            'sousCategoriesInscription' => SousCategorie::forUsage(UsageComptable::Inscription)->orderBy('nom')->get(),
        ]);
    }
}
