<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncConfig extends Component
{
    public ?int $compteHelloassoId = null;

    public ?int $compteVersementId = null;

    /**
     * DC-8 : le sélecteur liste des comptes (usage Don) — cette propriété porte
     * un id de compte (nom conservé jusqu'à DC-10). La colonne
     * helloasso_parametres.sous_categorie_don_id n'a pas de miroir compte :
     * conversion via le miroir DC-7 au mount (lecture) et au save (écriture).
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
            // Échafaudage DC-8, disparaît en DC-10 : sous_categorie_don_id → compte miroir.
            $this->sousCategorieDonId = $this->compteIdDepuisSousCategorie($p->sous_categorie_don_id);
        }
    }

    public function sauvegarder(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';

            return;
        }

        // Échafaudage DC-8, disparaît en DC-10 : le sélecteur émet un id de
        // compte, la colonne attend toujours un id sous_categories (pas de
        // colonne compte miroir sur helloasso_parametres).
        $sousCategorieDonId = $this->sousCategorieIdDepuisCompte($this->sousCategorieDonId ?: null);

        if (($this->sousCategorieDonId ?: null) !== null && $sousCategorieDonId === null) {
            $this->erreur = 'Ce compte n\'a pas de sous-catégorie correspondante.';

            return;
        }

        $p->update([
            'compte_helloasso_id' => $this->compteHelloassoId ?: null,
            'compte_versement_id' => $this->compteVersementId ?: null,
            'sous_categorie_don_id' => $sousCategorieDonId,
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
            // DC-8 : le sélecteur liste les comptes en usage Don.
            'comptesDon' => Compte::forUsage(UsageComptable::Don)->orderBy('numero_pcg')->get(),
        ]);
    }

    /** Miroir DC-7 : sous_categorie → compte (code_cerfa = numero_pcg). */
    private function compteIdDepuisSousCategorie(?int $sousCategorieId): ?int
    {
        if ($sousCategorieId === null) {
            return null;
        }

        $sc = SousCategorie::find($sousCategorieId);
        if ($sc === null || $sc->code_cerfa === null) {
            return null;
        }

        return Compte::ofNumero((string) $sc->code_cerfa)?->id;
    }

    /** Miroir DC-7 : compte → sous_categorie (numero_pcg = code_cerfa). */
    private function sousCategorieIdDepuisCompte(?int $compteId): ?int
    {
        if ($compteId === null) {
            return null;
        }

        $compte = Compte::find($compteId);
        if ($compte === null) {
            return null;
        }

        $id = SousCategorie::where('code_cerfa', $compte->numero_pcg)
            ->where('association_id', $compte->association_id)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
