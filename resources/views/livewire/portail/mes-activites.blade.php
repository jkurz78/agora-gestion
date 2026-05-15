<div>
    <h4 class="mb-3">{{ $titre }}</h4>

    @if ($aVenir->isEmpty() && $enCours->isEmpty() && $terminees->isEmpty())
        <p class="text-muted">Aucune activité enregistrée pour cette catégorie.</p>
    @endif

    @if ($aVenir->isNotEmpty())
        <h5 class="text-uppercase text-muted small mt-4 mb-2" style="letter-spacing:.05em;">À venir</h5>
        @foreach ($aVenir as $participation)
            @include('livewire.portail.mes-activites._carte', [
                'participation' => $participation,
                'horizon' => 'avenir',
                'portailAssociation' => $portailAssociation,
            ])
        @endforeach
    @endif

    @if ($enCours->isNotEmpty())
        <h5 class="text-uppercase text-muted small mt-4 mb-2" style="letter-spacing:.05em;">En cours</h5>
        @foreach ($enCours as $participation)
            @include('livewire.portail.mes-activites._carte', [
                'participation' => $participation,
                'horizon' => 'encours',
                'portailAssociation' => $portailAssociation,
            ])
        @endforeach
    @endif

    @if ($terminees->isNotEmpty())
        <h5 class="text-uppercase text-muted small mt-4 mb-2" style="letter-spacing:.05em;">Terminées</h5>
        @foreach ($terminees as $participation)
            @include('livewire.portail.mes-activites._carte', [
                'participation' => $participation,
                'horizon' => 'terminee',
                'portailAssociation' => $portailAssociation,
            ])
        @endforeach
    @endif
</div>
