<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Livewire\Parametres\Concerns\AutoriseEcranParametre;
use App\Models\CompteBancaire;
use App\Support\CurrentAssociation;
use App\Tenant\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

final class FacturationForm extends Component
{
    use AutoriseEcranParametre;

    public ?string $facture_conditions_reglement = null;

    public ?string $facture_mentions_legales = null;

    public ?string $facture_mentions_penalites = null;

    public ?int $facture_compte_bancaire_id = null;

    protected function cleEcranParametre(): string
    {
        return 'facturation';
    }

    public function mount(): void
    {
        $association = CurrentAssociation::get();

        $this->facture_conditions_reglement = $association->facture_conditions_reglement;
        $this->facture_mentions_legales = $association->facture_mentions_legales;
        $this->facture_mentions_penalites = $association->facture_mentions_penalites;
        $this->facture_compte_bancaire_id = $association->facture_compte_bancaire_id;
    }

    public function save(): void
    {
        $this->validate([
            'facture_conditions_reglement' => ['nullable', 'string', 'max:1000'],
            'facture_mentions_legales' => ['nullable', 'string', 'max:2000'],
            'facture_mentions_penalites' => ['nullable', 'string', 'max:2000'],
            // CompteBancaire étend TenantModel (scope global association_id),
            // mais une règle exists:comptes_bancaires,id interroge la table
            // brute et contourne ce scope. Et facture_compte_bancaire_id est
            // une propriété publique Livewire, donc modifiable côté client :
            // sans ce filtre explicite, rien n'empêcherait de poser
            // l'identifiant du compte bancaire d'une AUTRE association.
            'facture_compte_bancaire_id' => [
                'nullable',
                'integer',
                Rule::exists('comptes_bancaires', 'id')
                    ->where('association_id', TenantContext::currentId()),
            ],
        ]);

        CurrentAssociation::get()->fill([
            'facture_conditions_reglement' => $this->facture_conditions_reglement ?: null,
            'facture_mentions_legales' => $this->facture_mentions_legales ?: null,
            'facture_mentions_penalites' => $this->facture_mentions_penalites ?: null,
            'facture_compte_bancaire_id' => $this->facture_compte_bancaire_id,
        ])->save();

        session()->flash('success', 'Réglages de facturation mis à jour.');
    }

    public function render(): View
    {
        $comptesBancaires = CompteBancaire::saisieManuelle()->orderBy('nom')->get();

        return view('livewire.parametres.facturation-form', [
            'comptesBancaires' => $comptesBancaires,
        ]);
    }
}
