<div>
    <h4 class="mb-4">Mes activités</h4>

    {{-- Sous-tabs par type d'activité (visibles uniquement si ≥ 2 types distincts) --}}
    @if($typesActifs->count() > 1)
        <nav class="nav nav-pills mb-3">
            @foreach($typesActifs as $type)
                <button type="button"
                        wire:click="$set('typeOperationId', {{ $type->id }})"
                        class="nav-link {{ ((int) ($typeSelectionne?->id) === (int) $type->id) ? 'active' : '' }}">
                    {{ $type->nom }}
                </button>
            @endforeach
        </nav>
    @endif

    {{-- Section : À venir --}}
    <h5 class="mb-3">À venir</h5>
    @forelse ($aVenir as $participation)
        @include('livewire.portail.mes-activites._carte', ['participation' => $participation])
    @empty
        <p class="text-muted">Aucune activité dans cette catégorie.</p>
    @endforelse

    {{-- Section : En cours --}}
    <h5 class="mt-4 mb-3">En cours</h5>
    @forelse ($enCours as $participation)
        @include('livewire.portail.mes-activites._carte', ['participation' => $participation])
    @empty
        <p class="text-muted">Aucune activité dans cette catégorie.</p>
    @endforelse

    {{-- Section : Terminées --}}
    <h5 class="mt-4 mb-3">Terminée</h5>
    @forelse ($terminees as $participation)
        @include('livewire.portail.mes-activites._carte', ['participation' => $participation])
    @empty
        <p class="text-muted">Aucune activité dans cette catégorie.</p>
    @endforelse
</div>
