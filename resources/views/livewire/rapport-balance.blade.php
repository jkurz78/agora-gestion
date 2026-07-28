<div>
    <style>
        .balance-filter-label { font-size: 12px; font-weight: 700; color: #3d5473; text-transform: uppercase; letter-spacing: .02em; }
        .balance-table { font-size: 13px; border-collapse: collapse; width: 100%; }
        .balance-table th,
        .balance-table td { padding: 8px 10px; vertical-align: middle; }
        .balance-account { font-weight: 700; color: #1e3a5f; }
        .balance-account-label { color: #495057; }
        .balance-tier { color: #6c757d; font-size: 12px; margin-top: 2px; }
        .balance-total td { background: #5a7fa8; color: #fff; font-weight: 700; border-bottom: none; }
        .balance-zero { color: #adb5bd; }
    </style>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label for="balanceDateDebut" class="form-label balance-filter-label">Du</label>
                    <input
                        type="date"
                        id="balanceDateDebut"
                        class="form-control form-control-sm"
                        wire:model.live="dateDebut"
                    >
                </div>

                <div class="col-md-2">
                    <label for="balanceDateFin" class="form-label balance-filter-label">Au</label>
                    <input
                        type="date"
                        id="balanceDateFin"
                        class="form-control form-control-sm"
                        wire:model.live="dateFin"
                    >
                </div>

                <div class="col-md-4">
                    <label for="balanceComptes" class="form-label balance-filter-label">Comptes</label>
                    <input
                        type="text"
                        id="balanceComptes"
                        class="form-control form-control-sm"
                        wire:model.live.debounce.400ms="comptes"
                        placeholder="Ex. 4, 5, 6, 7 ou 40, 41"
                    >
                </div>

                <div class="col-md-3">
                    <label for="balanceColonnes" class="form-label balance-filter-label">Format</label>
                    <select
                        id="balanceColonnes"
                        class="form-select form-select-sm"
                        wire:model.live="colonnes"
                    >
                        <option value="2">2 colonnes</option>
                        <option value="4">4 colonnes</option>
                        <option value="6">6 colonnes</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <div class="mb-1">
                        <div class="form-check">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="balanceNonSoldes"
                                wire:model.live="uniquementNonSoldes"
                            >
                            <label class="form-check-label balance-filter-label" for="balanceNonSoldes">
                                Comptes non soldés
                            </label>
                        </div>
                        <div class="form-check">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="balanceDetailTiers"
                                wire:model.live="detailParTiers"
                            >
                            <label class="form-check-label balance-filter-label" for="balanceDetailTiers"
                                   title="Détaille les comptes collectifs 401 et 411 par tiers (balance auxiliaire)">
                                Détail par tiers
                            </label>
                        </div>
                    </div>
                </div>

                <div class="col-md-1 d-grid">
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-download me-1"></i>Exporter
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ $this->exportUrl('xlsx') }}"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a></li>
                            <li><a class="dropdown-item" href="{{ $this->exportUrl('pdf') }}" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php
        $lignes = $balance['lignes'];
        $totaux = $balance['totaux'];
        $totalSoldeOuvertureDebit = collect($lignes)->sum('solde_ouverture_debit_centimes');
        $totalSoldeOuvertureCredit = collect($lignes)->sum('solde_ouverture_credit_centimes');
        $colspanLibelle = 2;
        $colonnesMontants = $colonnes;
    @endphp

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 balance-table">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th rowspan="2" style="min-width:260px;">Compte</th>
                            <th rowspan="2">Intitulé</th>
                            @if($colonnes >= 4)
                                <th colspan="2" class="text-center">Ouverture</th>
                            @endif
                            @if($colonnes === 6)
                                <th colspan="2" class="text-center">Mouvements</th>
                            @endif
                            <th colspan="2" class="text-center">Solde final</th>
                        </tr>
                        <tr>
                            @if($colonnes >= 4)
                                <th class="text-end">Débit</th>
                                <th class="text-end">Crédit</th>
                            @endif
                            @if($colonnes === 6)
                                <th class="text-end">Débit</th>
                                <th class="text-end">Crédit</th>
                            @endif
                            <th class="text-end">Débit</th>
                            <th class="text-end">Crédit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($lignes as $ligne)
                            <tr>
                                <td data-sort="{{ $ligne['numero_compte'] }}|{{ $ligne['tiers'] ?? '' }}">
                                    <div class="balance-account">{{ $ligne['numero_compte'] }}</div>
                                    @if($ligne['tiers'] !== null)
                                        <div class="balance-tier">{{ $ligne['tiers'] }}</div>
                                    @endif
                                </td>
                                <td class="balance-account-label">{{ $ligne['intitule_compte'] }}</td>
                                @if($colonnes >= 4)
                                    <td class="text-end" data-sort="{{ $ligne['solde_ouverture_debit_centimes'] }}">
                                        <span class="{{ $ligne['solde_ouverture_debit_centimes'] === 0 ? 'balance-zero' : '' }}">
                                            {{ $this->formatCentimes((int) $ligne['solde_ouverture_debit_centimes']) }}
                                        </span>
                                    </td>
                                    <td class="text-end" data-sort="{{ $ligne['solde_ouverture_credit_centimes'] }}">
                                        <span class="{{ $ligne['solde_ouverture_credit_centimes'] === 0 ? 'balance-zero' : '' }}">
                                            {{ $this->formatCentimes((int) $ligne['solde_ouverture_credit_centimes']) }}
                                        </span>
                                    </td>
                                @endif
                                @if($colonnes === 6)
                                    <td class="text-end" data-sort="{{ $ligne['mouvement_debit_centimes'] }}">
                                        <span class="{{ $ligne['mouvement_debit_centimes'] === 0 ? 'balance-zero' : '' }}">
                                            {{ $this->formatCentimes((int) $ligne['mouvement_debit_centimes']) }}
                                        </span>
                                    </td>
                                    <td class="text-end" data-sort="{{ $ligne['mouvement_credit_centimes'] }}">
                                        <span class="{{ $ligne['mouvement_credit_centimes'] === 0 ? 'balance-zero' : '' }}">
                                            {{ $this->formatCentimes((int) $ligne['mouvement_credit_centimes']) }}
                                        </span>
                                    </td>
                                @endif
                                <td class="text-end" data-sort="{{ $ligne['solde_fin_debit_centimes'] }}">
                                    <span class="{{ $ligne['solde_fin_debit_centimes'] === 0 ? 'balance-zero' : '' }}">
                                        {{ $this->formatCentimes((int) $ligne['solde_fin_debit_centimes']) }}
                                    </span>
                                </td>
                                <td class="text-end" data-sort="{{ $ligne['solde_fin_credit_centimes'] }}">
                                    <span class="{{ $ligne['solde_fin_credit_centimes'] === 0 ? 'balance-zero' : '' }}">
                                        {{ $this->formatCentimes((int) $ligne['solde_fin_credit_centimes']) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $colspanLibelle + $colonnesMontants }}" class="text-center text-muted py-4">
                                    Aucune ligne pour cette sélection.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(count($lignes) > 0)
                        <tfoot>
                            <tr class="balance-total">
                                <td colspan="2">TOTAL</td>
                                @if($colonnes >= 4)
                                    <td class="text-end">{{ $this->formatCentimes((int) $totalSoldeOuvertureDebit) }}</td>
                                    <td class="text-end">{{ $this->formatCentimes((int) $totalSoldeOuvertureCredit) }}</td>
                                @endif
                                @if($colonnes === 6)
                                    <td class="text-end">{{ $this->formatCentimes((int) $totaux['mouvement_debit_centimes']) }}</td>
                                    <td class="text-end">{{ $this->formatCentimes((int) $totaux['mouvement_credit_centimes']) }}</td>
                                @endif
                                <td class="text-end">{{ $this->formatCentimes((int) $totaux['solde_fin_debit_centimes']) }}</td>
                                <td class="text-end">{{ $this->formatCentimes((int) $totaux['solde_fin_credit_centimes']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>
