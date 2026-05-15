<div>
    <h4 class="mb-3">Mes activités</h4>

    @if ($blocs->isEmpty())
        <p class="text-muted">Vous n'avez pas encore d'activité enregistrée.</p>
    @endif

    @foreach ($blocs as $bloc)
        @include('livewire.portail.mes-activites._bloc-type', [
            'bloc'               => $bloc,
            'portailAssociation' => $portailAssociation ?? null,
        ])
    @endforeach
</div>
