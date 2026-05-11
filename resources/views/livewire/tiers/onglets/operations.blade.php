<div class="operations-container">
    {{-- Section Participation (existante slice 7a) --}}
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

    {{-- Section A référé (slice 7b) --}}
    @if ($aReferre->totalCount > 0)
        <x-tiers.operations.section-card
            id="a-referre"
            titre="A référé"
            :compteur="$aReferre->totalCount"
        >
            @include('livewire.tiers.onglets.partials.a-referre-table', [
                'lignes' => $aReferre->lignes,
            ])
        </x-tiers.operations.section-card>
    @endif

    {{-- Section Suit (slice 7b) --}}
    @if ($suit->totalCount > 0)
        <x-tiers.operations.section-card
            id="suit"
            titre="Suit"
            :compteur="$suit->totalCount"
        >
            @include('livewire.tiers.onglets.partials.suit-table', [
                'lignes' => $suit->lignes,
            ])
        </x-tiers.operations.section-card>
    @endif

    {{-- Slice 7c : Encadrement --}}
</div>
