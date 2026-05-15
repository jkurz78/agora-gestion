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
    </div>
</div>
