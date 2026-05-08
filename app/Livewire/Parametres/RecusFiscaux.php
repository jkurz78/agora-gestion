<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Enums\RegimeFiscalDon;
use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Enum;
use Livewire\Component;

final class RecusFiscaux extends Component
{
    public bool $eligibleRecuFiscal = false;

    public string $regimeFiscalDon = '';

    public string $objetRecuFiscal = '';

    public string $rescritFiscalNumero = '';

    public ?string $rescritFiscalDate = null;

    public string $signataireNom = '';

    public string $signataireQualite = '';

    public bool $loiColucheEligible = false;

    public bool $ifiEligible = false;

    public function mount(): void
    {
        $this->requireAdmin();
        $asso = Association::findOrFail(TenantContext::currentId());
        $this->eligibleRecuFiscal = (bool) $asso->eligible_recu_fiscal;
        $this->regimeFiscalDon = $asso->regime_fiscal_don instanceof RegimeFiscalDon
            ? $asso->regime_fiscal_don->value
            : '';
        $this->objetRecuFiscal = (string) $asso->objet_recu_fiscal;
        $this->rescritFiscalNumero = (string) $asso->rescrit_fiscal_numero;
        $this->rescritFiscalDate = $asso->rescrit_fiscal_date?->format('Y-m-d');
        $this->signataireNom = (string) $asso->signataire_nom;
        $this->signataireQualite = (string) $asso->signataire_qualite;
        $this->loiColucheEligible = (bool) $asso->loi_coluche_eligible;
        $this->ifiEligible = (bool) $asso->ifi_eligible;
    }

    private function requireAdmin(): void
    {
        abort_unless(
            Auth::check() && Auth::user()->currentRole() === RoleAssociation::Admin->value,
            403,
        );
    }

    protected function rules(): array
    {
        return [
            'eligibleRecuFiscal' => ['boolean'],
            'regimeFiscalDon' => ['nullable', new Enum(RegimeFiscalDon::class)],
            'objetRecuFiscal' => ['nullable', 'string', 'max:5000'],
            'rescritFiscalNumero' => ['nullable', 'string', 'max:100'],
            'rescritFiscalDate' => ['nullable', 'date'],
            'signataireNom' => ['nullable', 'string', 'max:255'],
            'signataireQualite' => ['nullable', 'string', 'max:255'],
            'loiColucheEligible' => ['boolean'],
            'ifiEligible' => ['boolean'],
        ];
    }

    public function enregistrer(): void
    {
        $this->requireAdmin();
        $this->validate();

        $asso = Association::findOrFail(TenantContext::currentId());
        $asso->update([
            'eligible_recu_fiscal' => $this->eligibleRecuFiscal,
            'regime_fiscal_don' => $this->regimeFiscalDon ?: null,
            'objet_recu_fiscal' => $this->objetRecuFiscal ?: null,
            'rescrit_fiscal_numero' => $this->rescritFiscalNumero ?: null,
            'rescrit_fiscal_date' => $this->rescritFiscalDate,
            'signataire_nom' => $this->signataireNom ?: null,
            'signataire_qualite' => $this->signataireQualite ?: null,
            'loi_coluche_eligible' => $this->loiColucheEligible,
            'ifi_eligible' => $this->ifiEligible,
        ]);

        session()->flash('success', 'Paramètres reçus fiscaux enregistrés.');
    }

    public function render(): View
    {
        return view('livewire.parametres.recus-fiscaux', [
            'regimeOptions' => RegimeFiscalDon::options(),
        ])->layout('layouts.app-sidebar', ['title' => 'Reçus fiscaux']);
    }
}
