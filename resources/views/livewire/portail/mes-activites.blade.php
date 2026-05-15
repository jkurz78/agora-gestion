<div>
    <h4 class="mb-4">Mes activités</h4>

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
