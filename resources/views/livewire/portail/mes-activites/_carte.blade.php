<style>
    .pastille-future {
        background: #fff;
        border: 2px solid #3d5473;
    }
</style>

<div class="card mb-2 shadow-sm">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <span class="text-muted small">{{ $participation->operation->typeOperation->nom }}</span>
                <div class="fw-semibold">{{ $participation->operation->nom }}</div>
                @if ($participation->operation->seances->isNotEmpty())
                    <div class="small text-muted">
                        {{ $participation->operation->seances->min('date')->format('d/m/Y') }}
                        — {{ $participation->operation->seances->count() }} séance(s)
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
                @if (($horizon ?? '') === 'encours' && $participation->operation->seances->isNotEmpty())
                    @php
                        $presencesParSeance = $participation->presences->keyBy('seance_id');
                    @endphp
                    <ul class="seance-timeline list-unstyled mt-3 mb-0">
                        @foreach($participation->operation->seances->sortBy('date') as $seance)
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
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <span class="pastille rounded-circle {{ $pastilleClass }}"
                                      style="width:14px;height:14px;display:inline-block;"
                                      title="{{ $tooltipText }}"></span>
                                <span class="small">{{ $seance->date->format('d/m/Y') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- Bloc questionnaire : À venir + En cours uniquement, token actif --}}
        @if (in_array(($horizon ?? ''), ['avenir', 'encours'])
             && $participation->formulaireToken !== null
             && $participation->formulaireToken->expire_at->gte(today())
             && $participation->formulaireToken->rempli_at === null)
            @php $token = $participation->formulaireToken; @endphp
            <div class="alert alert-info mt-3 mb-0 small d-flex align-items-center justify-content-between gap-2">
                <div>
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Questionnaire à remplir.</strong>
                    Code : <code>{{ $token->token }}</code>
                </div>
                <a href="{{ route('formulaire.index', ['token' => $token->token]) }}"
                   target="_blank" rel="noopener"
                   class="btn btn-sm btn-primary">
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

        @if ($devis !== null || $facture !== null)
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
            </div>
        @endif
    </div>
</div>
