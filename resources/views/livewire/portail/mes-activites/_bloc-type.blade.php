<section class="mb-4">
    <h5 class="border-bottom pb-2 mb-3">{{ $bloc['type']->nom }}</h5>

    @if ($bloc['aVenir']->isNotEmpty())
        <h6 class="text-muted text-uppercase small mb-2" style="letter-spacing:.05em;">À venir</h6>
        @foreach ($bloc['aVenir'] as $participation)
            @include('livewire.portail.mes-activites._carte', [
                'participation'      => $participation,
                'horizon'            => 'avenir',
                'portailAssociation' => $portailAssociation ?? null,
            ])
        @endforeach
    @endif

    @if ($bloc['enCours']->isNotEmpty())
        <h6 class="text-muted text-uppercase small mb-2 mt-3" style="letter-spacing:.05em;">En cours</h6>
        @foreach ($bloc['enCours'] as $participation)
            @include('livewire.portail.mes-activites._carte', [
                'participation'      => $participation,
                'horizon'            => 'encours',
                'portailAssociation' => $portailAssociation ?? null,
            ])
        @endforeach
    @endif

    @if ($bloc['terminees']->isNotEmpty())
        <h6 class="text-muted text-uppercase small mb-2 mt-3" style="letter-spacing:.05em;">Terminées</h6>
        @foreach ($bloc['terminees'] as $participation)
            @include('livewire.portail.mes-activites._carte', [
                'participation'      => $participation,
                'horizon'            => 'terminee',
                'portailAssociation' => $portailAssociation ?? null,
            ])
        @endforeach
    @endif
</section>
