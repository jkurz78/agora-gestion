<div>
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-collection me-1"></i> Sélectionner les campagnes</h6>
        </div>
        <div class="card-body">
            <div class="row">
                @foreach ($toutesLesCampagnes as $titre => $groupe)
                    @if ($groupe->count() > 1)
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold small">{{ $titre }}</label>
                            @foreach ($groupe as $c)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           value="{{ $c->id }}"
                                           wire:model.live="campagneIds"
                                           id="camp-{{ $c->id }}">
                                    <label class="form-check-label" for="camp-{{ $c->id }}">
                                        {{ $c->operation->nom }}
                                        <span class="text-muted small">({{ $c->created_at->format('d/m/Y') }}) — {{ $c->soumises_count }} réponse{{ $c->soumises_count > 1 ? 's' : '' }} / {{ $c->invitations_count }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
            @if ($toutesLesCampagnes->filter(fn ($g) => $g->count() > 1)->isEmpty())
                <p class="text-muted mb-0">Aucun questionnaire identique utilisé sur plusieurs opérations.</p>
            @endif
        </div>
    </div>

    @if ($resultats !== null && $campagnes->count() >= 2)
        <div class="alert alert-info py-2 mb-3">
            <i class="bi bi-diagram-3 me-1"></i>
            Résultats consolidés de <strong>{{ $campagnes->count() }}</strong> campagnes :
            {{ $campagnes->map(fn ($c) => $c->operation->nom)->join(', ') }}
        </div>

        <div class="d-flex justify-content-end mb-3">
            <a href="{{ route('questionnaires.resultats.consolides.pdf', ['campagneIds' => $campagneIds]) }}"
               target="_blank"
               class="btn btn-outline-danger btn-sm">
                <i class="bi bi-file-earmark-pdf me-1"></i>Exporter en PDF
            </a>
        </div>

        @include('questionnaire.resultats._resultats', ['resultats' => $resultats, 'contacts' => $contacts, 'campagne' => $campagnes->first()])
    @elseif ($resultats !== null && $campagnes->count() === 1)
        <div class="alert alert-warning py-2">Sélectionnez au moins 2 campagnes pour consolider.</div>
    @endif
</div>
