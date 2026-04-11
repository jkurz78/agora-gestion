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
        <a href="{{ route('compta.banques.remises.index') }}" class="btn btn-outline-secondary">
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
                <div class="col-md-3">
                    <label class="form-label small mb-1">Opération</label>
                    <select wire:model.live="filterOperation" class="form-select form-select-sm">
                        <option value="">Toutes</option>
                        @foreach ($operations as $operation)
                            <option value="{{ $operation->id }}">{{ $operation->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Séance</label>
                    <select wire:model.live="filterSeance" class="form-select form-select-sm">
                        <option value="">Toutes</option>
                        @foreach ($seances as $seance)
                            <option value="{{ $seance->id }}">
                                S{{ $seance->numero }}
                                @if ($seance->titre) — {{ $seance->titre }} @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
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
                    @php
                        $visibleIds = $reglements->pluck('id')->toArray();
                        $allVisibleSelected = count($visibleIds) > 0 && count(array_intersect($visibleIds, $selectedIds)) === count($visibleIds);
                    @endphp
                    <tr>
                        <th style="width: 40px;">
                            @if ($this->canEdit && count($visibleIds) > 0)
                                <input type="checkbox"
                                       class="form-check-input"
                                       wire:key="select-all-{{ $filterOperation }}-{{ $filterSeance }}-{{ count($visibleIds) }}"
                                       @checked($allVisibleSelected)
                                       wire:click="toggleAll({{ json_encode($visibleIds) }})"
                                       title="Tout sélectionner / désélectionner">
                            @endif
                        </th>
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

    {{-- Tableau des transactions directes --}}
    @if($transactionsEligibles->isNotEmpty())
        <h6 class="mt-4 mb-2">Transactions (hors séances)</h6>
        <div class="table-responsive" style="margin-bottom: 80px;">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th style="width:40px">
                            @if($this->canEdit)
                                <input type="checkbox"
                                       class="form-check-input"
                                       wire:click="toggleAllTransactions"
                                       @checked(collect($transactionsEligibles->pluck('id'))->every(fn($id) => in_array($id, $selectedTransactionIds)))>
                            @endif
                        </th>
                        <th>Date</th>
                        <th>Libellé</th>
                        <th>Tiers</th>
                        <th>Compte</th>
                        <th class="text-end">Montant</th>
                    </tr>
                </thead>
                <tbody style="color:#555">
                    @foreach($transactionsEligibles as $tx)
                        <tr wire:key="tx-{{ $tx->id }}"
                            class="{{ in_array($tx->id, $selectedTransactionIds) ? 'table-primary' : '' }}"
                            @if($this->canEdit) style="cursor:pointer" wire:click="toggleTransaction({{ $tx->id }})" @endif>
                            <td>
                                @if($this->canEdit)
                                    <input type="checkbox"
                                           class="form-check-input"
                                           @checked(in_array($tx->id, $selectedTransactionIds))
                                           wire:click.stop="toggleTransaction({{ $tx->id }})">
                                @else
                                    <input type="checkbox"
                                           class="form-check-input"
                                           @checked(in_array($tx->id, $selectedTransactionIds))
                                           disabled>
                                @endif
                            </td>
                            <td class="small" data-sort="{{ $tx->date->format('Y-m-d') }}">{{ $tx->date->format('d/m/Y') }}</td>
                            <td class="small">{{ $tx->libelle }}</td>
                            <td class="small">{{ $tx->tiers?->displayName() ?? '—' }}</td>
                            <td class="small">{{ $tx->compte->nom }}</td>
                            <td class="text-end small fw-semibold text-nowrap" data-sort="{{ $tx->montant_total }}">{{ number_format((float) $tx->montant_total, 2, ',', "\u{00A0}") }}&nbsp;€</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Barre de pied fixe --}}
    <style>
        @media (min-width: 992px) {
            #selectionFooter { left: 220px; }
        }
    </style>
    <div class="fixed-bottom bg-white border-top shadow-sm py-2 px-4" style="z-index: 1040; margin-bottom: 32px;" id="selectionFooter">
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
