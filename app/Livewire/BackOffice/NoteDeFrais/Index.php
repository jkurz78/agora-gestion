<?php

declare(strict_types=1);

namespace App\Livewire\BackOffice\NoteDeFrais;

use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
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
        $this->authorize('treat', NoteDeFrais::class);
    }

    public function render(): View
    {
        $notes = $this->queryNotes();

        return view('livewire.back-office.note-de-frais.index', [
            'notes' => $notes,
            'onglet' => $this->onglet,
        ])->layout('layouts.app-sidebar', ['title' => 'Notes de frais']);
    }

    /** @return Collection<int, NoteDeFrais> */
    private function queryNotes(): Collection
    {
        $query = NoteDeFrais::with(['tiers', 'lignes'])
            ->orderByDesc('date');

        return match ($this->onglet) {
            'validees' => $query->whereIn('statut', [
                StatutNoteDeFrais::Validee->value,
                StatutNoteDeFrais::DonParAbandonCreances->value,
            ])->get(),
            'rejetees' => $query->where('statut', StatutNoteDeFrais::Rejetee->value)->get(),
            'toutes' => $query->whereIn('statut', [
                StatutNoteDeFrais::Soumise->value,
                StatutNoteDeFrais::Validee->value,
                StatutNoteDeFrais::Rejetee->value,
                StatutNoteDeFrais::DonParAbandonCreances->value,
            ])->get(),
            default => $query->where('statut', StatutNoteDeFrais::Soumise->value)->get(),
        };
    }
}
