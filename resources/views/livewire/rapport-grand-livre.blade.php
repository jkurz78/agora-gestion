<div>
    <style>
        .gl-filter-label { font-size: 12px; font-weight: 700; color: #3d5473; text-transform: uppercase; letter-spacing: .02em; }
        .gl-account-header { background: #3d5473; color: #fff; padding: 9px 12px; font-weight: 700; }
        .gl-account-subtitle { color: rgba(255,255,255,.8); font-size: 12px; font-weight: 400; }
        .gl-table { font-size: 13px; border-collapse: collapse; width: 100%; }
        .gl-table th,
        .gl-table td { padding: 7px 9px; vertical-align: middle; }
        .gl-total td { background: #5a7fa8; color: #fff; font-weight: 700; border-bottom: none; }
        .gl-opening td { background: #dce6f0; color: #1e3a5f; font-weight: 600; }
        .gl-tier { color: #6c757d; font-size: 12px; margin-top: 2px; }
        .gl-zero { color: #adb5bd; }
    </style>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label for="grandLivreDateDebut" class="form-label gl-filter-label">Du</label>
                    <input
                        type="date"
                        id="grandLivreDateDebut"
                        class="form-control form-control-sm"
                        wire:model.live="dateDebut"
                    >
                </div>

                <div class="col-md-2">
                    <label for="grandLivreDateFin" class="form-label gl-filter-label">Au</label>
                    <input
                        type="date"
                        id="grandLivreDateFin"
                        class="form-control form-control-sm"
                        wire:model.live="dateFin"
                    >
                </div>

                <div class="col-md-7">
                    <label for="grandLivreComptes" class="form-label gl-filter-label">Comptes</label>
                    <input
                        type="text"
                        id="grandLivreComptes"
                        class="form-control form-control-sm"
                        wire:model.live.debounce.400ms="comptes"
                        placeholder="Ex. 4, 5, 6, 7 ou 40, 41"
                    >
                </div>

                <div class="col-md-1 d-grid">
                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Exports Grand Livre à brancher">
                        <i class="bi bi-download me-1"></i>Exporter
                    </button>
                </div>
            </div>
        </div>
    </div>

    @forelse($grandLivre['comptes'] as $compte)
        <div class="card border-0 shadow-sm mb-3">
            <div class="gl-account-header">
                {{ $compte['numero_compte'] }} — {{ $compte['intitule_compte'] }}
                <span class="gl-account-subtitle ms-2">
                    Solde final : {{ $this->formatCentimes((int) $compte['solde_fin_centimes']) }}
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 gl-table">
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th style="width:105px;">Date</th>
                                <th style="width:90px;">Journal</th>
                                <th style="width:120px;">Pièce</th>
                                <th>Libellé</th>
                                <th style="width:160px;">Tiers</th>
                                <th class="text-end" style="width:115px;">Débit</th>
                                <th class="text-end" style="width:115px;">Crédit</th>
                                <th class="text-end" style="width:125px;">Solde</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="gl-opening">
                                <td colspan="7">Solde ouverture</td>
                                <td class="text-end">{{ $this->formatCentimes((int) $compte['solde_ouverture_centimes']) }}</td>
                            </tr>

                            @forelse($compte['lignes'] as $ligne)
                                <tr>
                                    <td data-sort="{{ $ligne['date'] }}">{{ \Carbon\Carbon::parse($ligne['date'])->format('d/m/Y') }}</td>
                                    <td>{{ $ligne['journal'] }}</td>
                                    <td>{{ $ligne['numero_piece'] ?? $ligne['reference'] ?? '—' }}</td>
                                    <td>{{ $ligne['libelle'] }}</td>
                                    <td>
                                        @if($ligne['tiers'] !== null)
                                            <div class="gl-tier">{{ $ligne['tiers'] }}</div>
                                        @else
                                            <span class="gl-zero">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end" data-sort="{{ $ligne['debit_centimes'] }}">
                                        <span class="{{ $ligne['debit_centimes'] === 0 ? 'gl-zero' : '' }}">
                                            {{ $this->formatCentimes((int) $ligne['debit_centimes']) }}
                                        </span>
                                    </td>
                                    <td class="text-end" data-sort="{{ $ligne['credit_centimes'] }}">
                                        <span class="{{ $ligne['credit_centimes'] === 0 ? 'gl-zero' : '' }}">
                                            {{ $this->formatCentimes((int) $ligne['credit_centimes']) }}
                                        </span>
                                    </td>
                                    <td class="text-end" data-sort="{{ $ligne['solde_progressif_centimes'] }}">
                                        {{ $this->formatCentimes((int) $ligne['solde_progressif_centimes']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        Aucun mouvement sur la période.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="gl-total">
                                <td colspan="5">TOTAL MOUVEMENTS</td>
                                <td class="text-end">{{ $this->formatCentimes((int) $compte['mouvement_debit_centimes']) }}</td>
                                <td class="text-end">{{ $this->formatCentimes((int) $compte['mouvement_credit_centimes']) }}</td>
                                <td class="text-end">{{ $this->formatCentimes((int) $compte['solde_fin_centimes']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-muted py-4">
                Aucune ligne pour cette sélection.
            </div>
        </div>
    @endforelse
</div>
