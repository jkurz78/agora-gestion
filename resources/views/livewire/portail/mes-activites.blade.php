<style>
    .pastille-future {
        background: #fff;
        border: 2px solid #3d5473;
    }
    .seance-timeline {
        flex-wrap: wrap;
    }
    @media (max-width: 767px) {
        .seance-timeline {
            flex-direction: column !important;
            align-items: flex-start !important;
        }
        .seance-timeline li {
            flex-direction: row !important;
            align-items: center !important;
            text-align: left !important;
            min-width: auto !important;
            gap: .5rem;
        }
        .seance-timeline li small { margin-top: 0 !important; }
    }
</style>

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
