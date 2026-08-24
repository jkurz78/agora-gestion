<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Enums\UsageComptable;
use App\Livewire\Parametres\Concerns\AutoriseEcranParametre;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class HelloassoSyncConfig extends Component
{
    use AutoriseEcranParametre;

    public ?int $compteHelloassoId = null;

    public ?int $compteVersementId = null;

    public ?int $compteDonId = null;

    public ?string $message = null;

    public ?string $erreur = null;

    protected function cleEcranParametre(): string
    {
        return 'helloasso';
    }

    public function mount(): void
    {
        $p = HelloAssoParametres::query()->first();
        if ($p !== null) {
            $this->compteHelloassoId = $p->compte_helloasso_id;
            $this->compteVersementId = $p->compte_versement_id;
            $this->compteDonId = $p->compte_don_id;
        }
    }

    public function sauvegarder(): void
    {
        $associationId = (int) TenantContext::currentId();
        $this->validate([
            'compteHelloassoId' => ['nullable', 'integer', Rule::exists('comptes_bancaires', 'id')->where('association_id', $associationId)],
            'compteVersementId' => ['nullable', 'integer', Rule::exists('comptes_bancaires', 'id')->where('association_id', $associationId)],
            'compteDonId' => ['nullable', 'integer', Rule::exists('comptes', 'id')->where('association_id', $associationId)],
        ]);

        $p = HelloAssoParametres::query()->first();
        if ($p === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';

            return;
        }

        $p->update([
            'compte_helloasso_id' => $this->compteHelloassoId ?: null,
            'compte_versement_id' => $this->compteVersementId ?: null,
            'compte_don_id' => $this->compteDonId ?: null,
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
