<div class="operations-container">
    @if ($participations->totalCount > 0)
        <x-tiers.operations.section-card
            id="participation"
            titre="Participation"
            :compteur="$participations->totalCount"
        >
            @include('livewire.tiers.onglets.partials.participations-table', [
                'lignes' => $participations->lignes,
            ])
        </x-tiers.operations.section-card>
    @endif

    {{-- Slice 7b : @include partials.prescripteur-table --}}
    {{-- Slice 7b : @include partials.medecin-table --}}
    {{-- Slice 7c : @include partials.encadrement-table --}}
</div>
