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
                <div class="card-body" wire:init="loadFormulaires">
                    @if ($formErreur)
                        <div class="alert alert-danger">{{ $formErreur }}</div>
                    @endif

                    @if ($formsLoading && ! $formsLoaded)
                        <div class="text-center py-3">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="text-muted mt-2">Chargement des formulaires HelloAsso...</p>
                        </div>
                    @elseif ($formsLoaded)
                        @if ($formMappings->isEmpty())
                            <p class="text-muted">Aucun formulaire trouvé pour cet exercice.</p>
                        @else
                            <table class="table table-sm">
                                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                    <tr>
                                        <th>Formulaire</th>
                                        <th>Type</th>
                                        <th>Période</th>
                                        <th>Statut</th>
                                        <th>Opération SVS</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($formMappings as $fm)
                                        <tr wire:key="fm-{{ $fm->id }}">
                                            <td class="small">{{ $fm->form_title ?? $fm->form_slug }}</td>
                                            <td class="small"><span class="badge text-bg-secondary">{{ $fm->form_type }}</span></td>
                                            <td class="small text-nowrap">
                                                @if ($fm->start_date || $fm->end_date)
                                                    {{ $fm->start_date?->format('d/m/Y') ?? '—' }}
                                                    → {{ $fm->end_date?->format('d/m/Y') ?? '…' }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="small">
                                                @if ($fm->state)
                                                    @php
                                                        $badgeClass = match($fm->state) {
                                                            'Public' => 'text-bg-success',
                                                            'Draft' => 'text-bg-warning',
                                                            'Private' => 'text-bg-info',
                                                            'Disabled' => 'text-bg-danger',
                                                            default => 'text-bg-secondary',
                                                        };
                                                    @endphp
                                                    <span class="badge {{ $badgeClass }}">{{ $fm->state }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                <select wire:model="formOperations.{{ $fm->id }}" class="form-select form-select-sm">
                                                    <option value="">Ne pas suivre</option>
                                                    @foreach ($operations as $op)
                                                        <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif

                        <div class="d-flex justify-content-end mt-3">
                            <button wire:click="sauvegarderEtSuite" class="btn btn-primary">
                                Suite <i class="bi bi-arrow-right ms-1"></i>
                            </button>
                        </div>
                    @endif
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
