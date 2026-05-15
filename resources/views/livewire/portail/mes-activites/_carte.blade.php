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
            </div>
        </div>
    </div>
</div>
