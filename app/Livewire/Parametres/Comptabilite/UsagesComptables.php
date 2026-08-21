<?php

declare(strict_types=1);

namespace App\Livewire\Parametres\Comptabilite;

use App\Enums\RoleAssociation;
use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Services\UsagesComptablesService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class UsagesComptables extends Component
{
    public ?int $fraisKmSelectedId = null;

    public ?int $abandonCreanceSelectedId = null;

    public ?int $gratuiteSelectedId = null;

    public bool $inlineOpen = false;

    public ?string $inlineUsage = null;

    public string $inlineNom = '';

    public ?string $inlineNumeroPcg = null;

    public function mount(): void
    {
        $this->requireAdmin();
        // DC-8 : les sélections portent des ids de comptes (classe 6/7).
        $fraisKm = Compte::forUsage(UsageComptable::FraisKilometriques)->first();
        $this->fraisKmSelectedId = $fraisKm?->id;
        $abandon = Compte::forUsage(UsageComptable::AbandonCreance)->first();
        $this->abandonCreanceSelectedId = $abandon?->id;
        $gratuite = Compte::forUsage(UsageComptable::Gratuite)->first();
        $this->gratuiteSelectedId = $gratuite?->id;
    }

    private function requireAdmin(): void
    {
        abort_unless(
            Auth::check() && Auth::user()->currentRole() === RoleAssociation::Admin->value,
            403,
        );
    }

    public function toggleDon(int $id, bool $active): void
    {
        $this->requireAdmin();
        app(UsagesComptablesService::class)->toggleDon($id, $active);
    }

    public function toggleCotisation(int $id, bool $active): void
    {
        $this->requireAdmin();
        app(UsagesComptablesService::class)->toggleCotisation($id, $active);
    }

    public function toggleInscription(int $id, bool $active): void
    {
        $this->requireAdmin();
        app(UsagesComptablesService::class)->toggleInscription($id, $active);
    }

    public function saveFraisKilometriques(): void
    {
        $this->requireAdmin();
        app(UsagesComptablesService::class)->setFraisKilometriques($this->fraisKmSelectedId);
    }

    public function saveAbandonCreance(): void
    {
        $this->requireAdmin();
        try {
            app(UsagesComptablesService::class)->setAbandonCreance($this->abandonCreanceSelectedId);
        } catch (DomainException $e) {
            $this->addError('abandonCreance', $e->getMessage());
        }
    }

    public function saveGratuite(): void
    {
        $this->requireAdmin();
        try {
            app(UsagesComptablesService::class)->setGratuite($this->gratuiteSelectedId);
        } catch (DomainException $e) {
            $this->addError('gratuite', $e->getMessage());
        }
    }

    public function openInline(string $usage): void
    {
        $this->requireAdmin();
        $this->reset(['inlineNom', 'inlineNumeroPcg']);
        $this->inlineUsage = $usage;
        $this->inlineOpen = true;
    }

    public function submitInline(): void
    {
        $this->requireAdmin();
        // Numéro de compte requis — sans lui, pas de Compte, donc la nouvelle
        // entrée serait invisible sur cet écran (liste de comptes).
        $this->validate([
            'inlineUsage' => 'required|string',
            'inlineNom' => 'required|string|max:255',
            'inlineNumeroPcg' => 'required|string|max:20',
        ]);
        $usage = UsageComptable::from($this->inlineUsage);
        try {
            app(UsagesComptablesService::class)->createAndFlag([
                'intitule' => $this->inlineNom,
                'numero_pcg' => $this->inlineNumeroPcg,
            ], $usage);
        } catch (DomainException $e) {
            $this->addError('inlineNumeroPcg', $e->getMessage());

            return;
        }
        $this->inlineOpen = false;
        $this->dispatch('usage-created');
    }

    public function getAbandonCreanceCandidatesProperty(): array
    {
        return Compte::forUsage(UsageComptable::Don)->orderBy('numero_pcg')->get()->all();
    }

    public function render(): View
    {
        return view('livewire.parametres.comptabilite.usages-comptables', [
            'comptesDepense' => Compte::where('classe', 6)->where('actif', true)->orderBy('numero_pcg')->get(),
            'comptesRecette' => Compte::where('classe', 7)->where('actif', true)->orderBy('numero_pcg')->get(),
            'comptesDon' => Compte::forUsage(UsageComptable::Don)->pluck('id'),
            'comptesCotisation' => Compte::forUsage(UsageComptable::Cotisation)->pluck('id'),
            'comptesInscription' => Compte::forUsage(UsageComptable::Inscription)->pluck('id'),
        ])->layout('layouts.app-sidebar', ['title' => 'Comptabilité']);
    }
}
