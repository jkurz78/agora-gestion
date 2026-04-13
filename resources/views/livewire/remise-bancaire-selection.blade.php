<div>
    {{-- En-tête --}}
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h1 class="mb-1">Sélection des transactions</h1>
            <p class="text-muted mb-0">
                {{ $remise->libelle }} — {{ $remise->date->format('d/m/Y') }} —
                {{ $remise->mode_paiement->label() }} —
                Banque : <strong>{{ $remise->compteCible->nom }}</strong>
            </p>
        </div>
        <a href="{{ route('banques.remises.index') }}" class="btn btn-outline-secondary">
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
                    <label class="form-label small mb-1">Tiers</label>
                    <input type="text" wire:model.live.debounce.300ms="filterTiers"
                           class="form-control form-control-sm" placeholder="Nom, prénom ou entreprise…">
                </div>
            </div>
        </div>
    </div>

    {{-- Tableau des transactions --}}
    @if ($transactions->isEmpty())
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Aucune transaction disponible pour ce mode de paiement.
        </div>
    @else
        <div class="table-responsive" style="margin-bottom: 80px;">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    @php
                        $visibleIds = $transactions->pluck('id')->map(fn ($id) => (int) $id)->all();
                        $allVisibleSelected = count($visibleIds) > 0
                            && collect($visibleIds)->every(fn ($id) => in_array($id, $selectedTransactionIds, true));
                    @endphp
                    <tr>
                        <th style="width: 40px;">
                            @if ($this->canEdit && count($visibleIds) > 0)
                                <input type="checkbox"
                                       class="form-check-input"
                                       wire:key="select-all-{{ $filterOperation }}-{{ $filterTiers }}-{{ count($visibleIds) }}"
                                       @checked($allVisibleSelected)
                                       wire:click="toggleAll()"
                                       title="Tout sélectionner / désélectionner">
                            @endif
                        </th>
                        <th>Date</th>
                        <th>Libellé</th>
                        <th>Tiers</th>
                        <th>Opération</th>
                        <th>Statut</th>
                        <th class="text-end">Montant</th>
                    </tr>
                </thead>
                <tbody style="color:#555">
                    @foreach ($transactions as $tx)
                        @php $isSelected = in_array((int) $tx->id, $selectedTransactionIds, true); @endphp
                        <tr wire:key="tx-{{ $tx->id }}"
                            class="{{ $isSelected ? 'table-success' : '' }}"
                            @if ($this->canEdit) style="cursor: pointer;" wire:click="toggleTransaction({{ $tx->id }})" @endif>
                            <td>
                                @if ($this->canEdit)
                                    <input type="checkbox"
                                           class="form-check-input"
                                           @checked($isSelected)
                                           wire:click.stop="toggleTransaction({{ $tx->id }})">
                                @else
                                    <input type="checkbox"
                                           class="form-check-input"
                                           @checked($isSelected)
                                           disabled>
                                @endif
                            </td>
                            <td class="small" data-sort="{{ $tx->date->format('Y-m-d') }}">{{ $tx->date->format('d/m/Y') }}</td>
                            <td class="small">{{ $tx->libelle }}</td>
                            <td class="small">{{ $tx->tiers?->displayName() ?? '—' }}</td>
                            <td class="small">{{ $tx->lignes->first()?->operation?->nom ?? '—' }}</td>
                            <td class="small">
                                @if ($tx->statut_reglement === \App\Enums\StatutReglement::Recu)
                                    <span class="badge bg-success">{{ $tx->statut_reglement->label() }}</span>
                                @else
                                    <span class="badge bg-warning text-dark">{{ $tx->statut_reglement->label() }}</span>
                                @endif
                            </td>
                            <td class="text-end small fw-semibold text-nowrap" data-sort="{{ $tx->montant_total }}">
                                {{ number_format((float) $tx->montant_total, 2, ',', "\u{00A0}") }}&nbsp;€
                            </td>
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
                <strong>{{ $countSelected }}</strong> transaction{{ $countSelected > 1 ? 's' : '' }} sélectionnée{{ $countSelected > 1 ? 's' : '' }}
                — Total : <strong>{{ number_format((float) $totalSelected, 2, ',', "\u{00A0}") }}&nbsp;€</strong>
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
