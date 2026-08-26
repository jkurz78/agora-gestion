<div>
    <div class="d-flex justify-content-end align-items-center gap-3 mb-3">
        <div class="form-check form-switch mb-0">
            <input type="checkbox" wire:model.live="compareN1" class="form-check-input" id="bilanToggleN1">
            <label class="form-check-label small" for="bilanToggleN1">Afficher l’exercice N-1</label>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="{{ $this->exportUrl('pdf') }}" target="_blank">
            <i class="bi bi-file-earmark-pdf me-1"></i>Exporter en PDF
        </a>
    </div>

    @if ($bilan['provisoire'])
        <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>{{ $bilan['statut'] }}</span>
        </div>
    @endif

    @php
        $ecartN = (int) $bilan['ecart_actif_passif']['n_centimes'];
    @endphp
    @if ($ecartN === 0)
        <div class="alert alert-success d-flex align-items-center gap-2" role="status">
            <i class="bi bi-check-circle-fill"></i>
            <span>Bilan équilibré</span>
        </div>
    @else
        <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <span>Bilan déséquilibré — Écart actif/passif : {{ $this->formatCentimes($ecartN) }}</span>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>ACTIF</th>
                                    <th class="text-end">Brut {{ $bilan['label_n'] }}</th>
                                    <th class="text-end">Amort. &amp; prov. {{ $bilan['label_n'] }}</th>
                                    <th class="text-end">Net {{ $bilan['label_n'] }}</th>
                                    @if ($compareN1)
                                        <th class="text-end">Net {{ $bilan['label_n_1'] }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($bilan['actif'] as $ligne)
                                    <tr>
                                        <td>{{ $ligne['libelle'] }}</td>
                                        <td class="text-end" data-sort="{{ $ligne['brut_n_centimes'] }}">{{ $this->formatCentimes((int) $ligne['brut_n_centimes']) }}</td>
                                        <td class="text-end" data-sort="{{ $ligne['amortissements_provisions_n_centimes'] }}">{{ $this->formatCentimes((int) $ligne['amortissements_provisions_n_centimes']) }}</td>
                                        <td class="text-end" data-sort="{{ $ligne['net_n_centimes'] }}">{{ $this->formatCentimes((int) $ligne['net_n_centimes']) }}</td>
                                        @if ($compareN1)
                                            <td class="text-end" data-sort="{{ $ligne['net_n_1_centimes'] }}">{{ $this->formatCentimes((int) $ligne['net_n_1_centimes']) }}</td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $compareN1 ? 5 : 4 }}" class="text-center text-muted py-4">Aucune rubrique d’actif.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold table-light">
                                    <td>TOTAL ACTIF</td>
                                    <td class="text-end" data-sort="{{ $bilan['totaux']['actif_n_brut_centimes'] }}">{{ $this->formatCentimes((int) $bilan['totaux']['actif_n_brut_centimes']) }}</td>
                                    <td class="text-end" data-sort="{{ $bilan['totaux']['actif_n_amortissements_provisions_centimes'] }}">{{ $this->formatCentimes((int) $bilan['totaux']['actif_n_amortissements_provisions_centimes']) }}</td>
                                    <td class="text-end" data-sort="{{ $bilan['totaux']['actif_n_net_centimes'] }}">{{ $this->formatCentimes((int) $bilan['totaux']['actif_n_net_centimes']) }}</td>
                                    @if ($compareN1)
                                        <td class="text-end" data-sort="{{ $bilan['totaux']['actif_n_1_net_centimes'] }}">{{ $this->formatCentimes((int) $bilan['totaux']['actif_n_1_net_centimes']) }}</td>
                                    @endif
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>PASSIF</th>
                                    <th class="text-end">{{ $bilan['label_n'] }}</th>
                                    @if ($compareN1)
                                        <th class="text-end">{{ $bilan['label_n_1'] }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($bilan['passif'] as $ligne)
                                    <tr>
                                        <td>{{ $ligne['libelle'] }}</td>
                                        <td class="text-end" data-sort="{{ $ligne['montant_n_centimes'] }}">{{ $this->formatCentimes((int) $ligne['montant_n_centimes']) }}</td>
                                        @if ($compareN1)
                                            <td class="text-end" data-sort="{{ $ligne['montant_n_1_centimes'] }}">{{ $this->formatCentimes((int) $ligne['montant_n_1_centimes']) }}</td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $compareN1 ? 3 : 2 }}" class="text-center text-muted py-4">Aucune rubrique de passif.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold table-light">
                                    <td>TOTAL PASSIF</td>
                                    <td class="text-end" data-sort="{{ $bilan['totaux']['passif_n_centimes'] }}">{{ $this->formatCentimes((int) $bilan['totaux']['passif_n_centimes']) }}</td>
                                    @if ($compareN1)
                                        <td class="text-end" data-sort="{{ $bilan['totaux']['passif_n_1_centimes'] }}">{{ $this->formatCentimes((int) $bilan['totaux']['passif_n_1_centimes']) }}</td>
                                    @endif
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
