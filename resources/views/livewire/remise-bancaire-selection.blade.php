<div>
    {{-- En-tête --}}
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="mb-1">Sélection des règlements</h1>
            <p class="text-muted mb-0">
                {{ $remise->libelle }} — {{ $remise->date->format('d/m/Y') }} —
                {{ $remise->mode_paiement->label() }} —
                Banque : <strong>{{ $remise->compteCible->nom }}</strong>
            </p>
        </div>
        <a href="{{ route('gestion.remises-bancaires') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
    </div>

    @error('selection')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    {{-- Filtres --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Opération</label>
                    <select wire:model.live="filterOperation" class="form-select form-select-sm">
                        <option value="">Toutes</option>
                        @foreach ($operations as $operation)
                            <option value="{{ $operation->id }}">{{ $operation->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1">Participant</label>
                    <input type="text" wire:model.live.debounce.300ms="filterTiers"
                           class="form-control form-control-sm" placeholder="Nom ou prénom…">
                </div>
            </div>
        </div>
    </div>

    {{-- Tableau des règlements --}}
    @if ($reglements->isEmpty())
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Aucun règlement disponible pour ce type de paiement.
        </div>
    @else
        <div class="table-responsive" style="margin-bottom: 80px;">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th style="width: 40px;"></th>
                        <th>Participant</th>
                        <th>Opération</th>
                        <th>Séance</th>
                        <th class="text-end">Montant</th>
                    </tr>
                </thead>
                <tbody style="color:#555">
                    @foreach ($reglements as $reglement)
                        <tr wire:key="reg-{{ $reglement->id }}"
                            class="{{ in_array($reglement->id, $selectedIds) ? 'table-success' : '' }}"
                            @if ($this->canEdit) style="cursor: pointer;" wire:click="toggleReglement({{ $reglement->id }})" @endif>
                            <td>
                                @if ($this->canEdit)
                                    <input type="checkbox"
                                           class="form-check-input"
                                           @checked(in_array($reglement->id, $selectedIds))
                                           wire:click.stop="toggleReglement({{ $reglement->id }})">
                                @else
                                    <input type="checkbox"
                                           class="form-check-input"
                                           @checked(in_array($reglement->id, $selectedIds))
                                           disabled>
                                @endif
                            </td>
                            <td class="small">{{ $reglement->participant->tiers->displayName() }}</td>
                            <td class="small">{{ $reglement->seance->operation->nom }}</td>
                            <td class="small">
                                S{{ $reglement->seance->numero }}
                                @if ($reglement->seance->titre)
                                    — {{ $reglement->seance->titre }}
                                @endif
                            </td>
                            <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $reglement->montant_prevu, 2, ',', ' ') }} €</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Barre de pied fixe --}}
    <div class="fixed-bottom bg-white border-top shadow-sm py-2 px-4" style="z-index: 1040; margin-bottom: 32px;">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <div>
                <strong>{{ $countSelected }}</strong> règlement{{ $countSelected > 1 ? 's' : '' }} sélectionné{{ $countSelected > 1 ? 's' : '' }}
                — Total : <strong>{{ number_format((float) $totalSelected, 2, ',', ' ') }} €</strong>
            </div>
            @if ($this->canEdit)
                <button wire:click="valider"
                        class="btn btn-success"
                        @disabled($countSelected === 0)>
                    <i class="bi bi-check-lg"></i> Valider la sélection
                </button>
            @endif
        </div>
    </div>
</div>
