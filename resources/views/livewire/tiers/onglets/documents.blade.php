<div>
    @if(! empty($timeline->recusFiscaux))
        @include('livewire.tiers.onglets.partials.documents-recus-fiscaux', ['lignes' => $timeline->recusFiscaux])
    @endif

    @if(! empty($timeline->facturesEmises))
        @include('livewire.tiers.onglets.partials.documents-factures-emises', ['lignes' => $timeline->facturesEmises])
    @endif

    @if(! empty($timeline->documentsPrevisionnels))
        @include('livewire.tiers.onglets.partials.documents-previsionnels', ['lignes' => $timeline->documentsPrevisionnels])
    @endif

    @if(! empty($timeline->facturesDeposees))
        @include('livewire.tiers.onglets.partials.documents-factures-deposees', ['lignes' => $timeline->facturesDeposees])
    @endif

    @if(! empty($timeline->justificatifsParticipants))
        @include('livewire.tiers.onglets.partials.documents-justificatifs-participants', ['lignes' => $timeline->justificatifsParticipants])
    @endif

    @if(! empty($timeline->piecesJointes))
        @include('livewire.tiers.onglets.partials.documents-pieces-jointes', ['lignes' => $timeline->piecesJointes])
    @endif
</div>
