<?php

declare(strict_types=1);

namespace App\Livewire\BackOffice\FacturePartenaire;

use App\Enums\StatutFactureDeposee;
use App\Models\FacturePartenaireDeposee;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class Index extends Component
{
    #[Url(as: 'onglet')]
    public string $onglet = 'a_traiter';

    public function mount(): void
    {
        $this->authorize('treat', FacturePartenaireDeposee::class);
    }

    public function render(): View
    {
        $depots = $this->queryDepots();

        return view('livewire.back-office.facture-partenaire.index', [
            'depots' => $depots,
            'onglet' => $this->onglet,
        ])->layout('layouts.app-sidebar', ['title' => 'Factures à comptabiliser']);
    }

    /** @return Collection<int, FacturePartenaireDeposee> */
    private function queryDepots(): Collection
    {
        $query = FacturePartenaireDeposee::with(['tiers'])
            ->orderByDesc('date_facture');

        return match ($this->onglet) {
            'traitees' => $query->where('statut', StatutFactureDeposee::Traitee->value)->get(),
            'rejetees' => $query->where('statut', StatutFactureDeposee::Rejetee->value)->get(),
            'toutes' => $query->whereIn('statut', [
                StatutFactureDeposee::Soumise->value,
                StatutFactureDeposee::Traitee->value,
                StatutFactureDeposee::Rejetee->value,
            ])->get(),
            default => $query->where('statut', StatutFactureDeposee::Soumise->value)->get(),
        };
    }
}
