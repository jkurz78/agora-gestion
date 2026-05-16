<div class="card mb-2 shadow-sm">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="fw-semibold">{{ $participation->operation->nom }}</div>
                @php
                    $seancesAvecDate = $participation->operation->seances->filter(fn($s) => $s->date !== null);
                @endphp
                @if ($seancesAvecDate->isNotEmpty())
                    <div class="small text-muted">
                        {{ $seancesAvecDate->min('date')->format('d/m/Y') }}
                        — {{ $participation->operation->nombre_seances ?? $participation->operation->seances->count() }} séance(s)
                    </div>
                @elseif ($participation->operation->date_debut)
                    <div class="small text-muted">
                        Du {{ $participation->operation->date_debut->format('d/m/Y') }}
                        @if ($participation->operation->date_fin)
                            au {{ $participation->operation->date_fin->format('d/m/Y') }}
                        @endif
                    </div>
                @else
                    <div class="small text-muted">Inscrit le {{ $participation->date_inscription->format('d/m/Y') }}</div>
                @endif

                {{-- Timeline séances : section En cours uniquement --}}
                @if (($horizon ?? '') === 'encours' && $seancesAvecDate->isNotEmpty())
                    @php
                        $presencesParSeance = $participation->presences->keyBy('seance_id');
                    @endphp
                    <ul class="seance-timeline list-unstyled mt-3 mb-0 d-flex flex-md-row flex-column gap-2 gap-md-3 align-items-md-start">
                        @foreach($seancesAvecDate->sortBy('date') as $seance)
                            @php
                                $presence = $presencesParSeance->get($seance->id);
                                $statut = $presence?->statut ?? null;
                                [$pastilleClass, $tooltipText] = match (true) {
                                    $statut === \App\Enums\StatutPresence::Present->value => ['bg-success', 'Présent'],
                                    $statut === \App\Enums\StatutPresence::Excuse->value => ['bg-warning', 'Excusé'],
                                    $statut === \App\Enums\StatutPresence::AbsenceNonJustifiee->value => ['bg-danger', 'Absent (non justifié)'],
                                    $statut === \App\Enums\StatutPresence::Arret->value => ['bg-secondary', 'Arrêt'],
                                    $seance->date->gt(today()) => ['pastille-future', 'À venir'],
                                    default => ['bg-light border', 'Non renseigné'],
                                };
                            @endphp
                            <li class="d-flex flex-column align-items-center text-center" style="min-width:50px;">
                                <span class="pastille rounded-circle {{ $pastilleClass }}"
                                      style="width:18px;height:18px;display:inline-block;"
                                      title="{{ $tooltipText }}"></span>
                                <small class="mt-1">{{ $seance->date->format('d/m') }}</small>
                                @if($statut === \App\Enums\StatutPresence::Present->value)
                                    <a href="{{ \App\Support\PortailRoute::to('attestations.seance', $portailAssociation ?? null, ['operation' => $participation->operation_id, 'seance' => $seance->id]) }}"
                                       target="_blank" rel="noopener"
                                       class="btn btn-sm btn-link p-0 mt-1"
                                       title="Voir l'attestation">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Bloc financier --}}
                @php
                    $totalPrevu = $participation->totalPrevu();
                    $totalRegle = $participation->totalRegle();
                    $resteARegler = $participation->resteARegler();
                @endphp

                @if ($totalPrevu > 0)
                    <div class="mt-3 p-2 bg-light rounded small d-flex flex-wrap align-items-center gap-3">
                        <span><strong>Total dû :</strong> {{ number_format($totalPrevu, 2, ',', ' ') }} €</span>
                        <span class="text-muted">·</span>
                        <span><strong>Réglé :</strong> {{ number_format($totalRegle, 2, ',', ' ') }} €</span>
                        @if ($totalRegle === 0.0)
                            <span class="badge bg-secondary ms-auto">En attente de règlement</span>
                        @elseif ($resteARegler > 0)
                            <span class="text-muted">·</span>
                            <span><strong>Reste :</strong> {{ number_format($resteARegler, 2, ',', ' ') }} €</span>
                            <span class="badge bg-warning text-dark ms-auto">À régler</span>
                        @else
                            <span class="badge bg-success ms-auto">À jour</span>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Bloc questionnaire : À venir + En cours uniquement, token actif --}}
        @if (in_array(($horizon ?? ''), ['avenir', 'encours'])
             && $participation->formulaireToken !== null
             && $participation->formulaireToken->expire_at->gte(today())
             && $participation->formulaireToken->rempli_at === null)
            @php $token = $participation->formulaireToken; @endphp
            <div class="alert alert-info mt-3 mb-0 small d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                <div>
                    <i class="bi bi-info-circle me-1"></i>
                    Pour finaliser votre inscription à <strong>{{ $participation->operation->typeOperation->nom }}</strong> · <strong>{{ $participation->operation->nom }}</strong>, vous avez un questionnaire à remplir.
                    Le code pour signer votre réponse au questionnaire est : <code>{{ $token->token }}</code>
                </div>
                <a href="{{ route('formulaire.index', ['token' => $token->token]) }}"
                   target="_blank" rel="noopener"
                   class="btn btn-sm btn-primary text-nowrap">
                    Ouvrir le questionnaire
                </a>
            </div>
        @endif

        {{-- Boutons documents selon horizon --}}
        @php
            $devis = in_array(($horizon ?? ''), ['avenir', 'encours'])
                ? $participation->devisProformaLePlusRecent()
                : null;
            $facture = in_array(($horizon ?? ''), ['encours', 'terminee'])
                ? $participation->factureRattachee()
                : null;
        @endphp

        @if ($devis !== null || $facture !== null || ($horizon ?? '') === 'terminee')
            <div class="mt-3 d-flex flex-wrap gap-2">
                @if ($devis !== null)
                    <a href="{{ \App\Support\PortailRoute::to('documents.devis', $portailAssociation ?? null, ['document' => $devis->id]) }}"
                       target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-file-earmark-text"></i>
                        Voir le {{ $devis->type === \App\Enums\TypeDocumentPrevisionnel::Devis ? 'devis' : 'pro forma' }}
                    </a>
                @endif

                @if ($facture !== null)
                    <a href="{{ \App\Support\PortailRoute::to('documents.facture', $portailAssociation ?? null, ['facture' => $facture->id]) }}"
                       target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-receipt"></i>
                        @if (($horizon ?? '') === 'encours')
                            Voir la facture en cours
                        @else
                            Voir la facture finale
                        @endif
                    </a>
                @endif

                @if (($horizon ?? '') === 'terminee')
                    <a href="{{ \App\Support\PortailRoute::to('attestations.recap', $portailAssociation ?? null, ['operation' => $participation->operation_id, 'participant' => $participation->id]) }}"
                       target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-file-earmark-pdf"></i> Voir l'attestation globale
                    </a>
                @endif
            </div>
        @endif
    </div>
</div>
