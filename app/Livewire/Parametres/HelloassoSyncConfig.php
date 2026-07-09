<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncConfig extends Component
{
    public ?int $compteHelloassoId = null;

    public ?int $compteVersementId = null;

    /**
     * DC-10a : le sélecteur liste des comptes (usage Don) et la colonne
     * helloasso_parametres.compte_don_id porte directement l'id de compte
     * (nom de propriété conservé jusqu'au renommage vocabulaire).
     */
    public ?int $sousCategorieDonId = null;

    public ?string $message = null;

    public ?string $erreur = null;

    public function mount(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p !== null) {
            $this->compteHelloassoId = $p->compte_helloasso_id;
            $this->compteVersementId = $p->compte_versement_id;
            $this->sousCategorieDonId = $p->compte_don_id;
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
            'compte_don_id' => $this->sousCategorieDonId ?: null,
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
            // Le sélecteur liste les comptes en usage Don.
            'comptesDon' => Compte::forUsage(UsageComptable::Don)->orderBy('numero_pcg')->get(),
        ]);
    }
}
