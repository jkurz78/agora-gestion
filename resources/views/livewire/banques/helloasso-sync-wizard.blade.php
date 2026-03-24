<div>
    {{-- Erreurs bloquantes --}}
    @if ($configBloquante)
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-octagon me-1"></i>
            <strong>Configuration incomplète</strong>
            <ul class="mb-0 mt-1">
                @foreach ($configErrors as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
            <a href="{{ route('parametres.helloasso') }}" class="alert-link d-block mt-2">
                <i class="bi bi-gear me-1"></i> Paramètres → Connexion HelloAsso
            </a>
        </div>
    @else
        {{-- Avertissements non bloquants --}}
        @if (count($configWarnings) > 0)
            <div class="alert alert-warning small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <ul class="mb-0">
                    @foreach ($configWarnings as $warn)
                        <li>{{ $warn }}</li>
                    @endforeach
                </ul>
                <a href="{{ route('parametres.helloasso') }}" class="alert-link d-block mt-1">
                    Configurer dans Paramètres → Connexion HelloAsso
                </a>
            </div>
        @endif

        {{-- Étape 1 --}}
        <div class="card mb-3 {{ $step === 1 ? 'border-primary' : '' }}"
             style="{{ $step === 1 ? 'border-width:2px' : '' }}">
            <div class="card-header d-flex align-items-center gap-2"
                 @if ($step > 1) wire:click="goToStep(1)" @endif
                 style="{{ $step > 1 ? 'cursor:pointer' : '' }}">
                <span class="badge rounded-pill {{ $step > 1 ? 'bg-success' : ($step === 1 ? 'bg-primary' : 'bg-secondary') }}">1</span>
                <strong>Mapping Formulaires → Opérations</strong>
                @if ($step > 1)
                    <span class="ms-auto small text-muted">{{ $stepOneSummary ?? '' }}</span>
                @endif
            </div>
            @if ($step === 1)
                <div class="card-body">
                    <p class="text-muted">Contenu étape 1 (task suivante)</p>
                </div>
            @endif
        </div>

        {{-- Étape 2 --}}
        <div class="card mb-3 {{ $step === 2 ? 'border-primary' : '' }}"
             style="{{ $step === 2 ? 'border-width:2px' : '' }}">
            <div class="card-header d-flex align-items-center gap-2"
                 @if ($step > 2) wire:click="goToStep(2)" @endif
                 style="{{ $step > 2 ? 'cursor:pointer' : '' }}">
                <span class="badge rounded-pill {{ $step > 2 ? 'bg-success' : ($step === 2 ? 'bg-primary' : 'bg-secondary') }}">2</span>
                <strong>Rapprochement des Tiers</strong>
                @if ($step > 2)
                    <span class="ms-auto small text-muted">{{ $stepTwoSummary ?? '' }}</span>
                @endif
            </div>
            @if ($step === 2)
                <div class="card-body">
                    <p class="text-muted">Contenu étape 2 (task suivante)</p>
                </div>
            @endif
        </div>

        {{-- Étape 3 --}}
        <div class="card mb-3 {{ $step === 3 ? 'border-primary' : '' }}"
             style="{{ $step === 3 ? 'border-width:2px' : '' }}">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="badge rounded-pill {{ $step === 3 ? 'bg-primary' : 'bg-secondary' }}">3</span>
                <strong>Synchronisation</strong>
                @if ($step > 3)
                    <span class="ms-auto small text-muted">{{ $stepThreeSummary ?? '' }}</span>
                @endif
            </div>
            @if ($step === 3)
                <div class="card-body">
                    <p class="text-muted">Contenu étape 3 (task suivante)</p>
                </div>
            @endif
        </div>
    @endif
</div>
