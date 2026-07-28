<div>
    <style>
        .jx-filter-label { font-size: 12px; font-weight: 700; color: #3d5473; text-transform: uppercase; letter-spacing: .02em; }
        .jx-journal-header { background: #3d5473; color: #fff; padding: 9px 12px; font-weight: 700; }
        .jx-journal-subtitle { color: rgba(255,255,255,.8); font-size: 12px; font-weight: 400; }
        .jx-table { font-size: 13px; border-collapse: collapse; width: 100%; }
        .jx-table th,
        .jx-table td { padding: 7px 9px; vertical-align: middle; }
        .jx-piece td { background: #dce6f0; color: #1e3a5f; font-weight: 600; }
        .jx-total td { background: #5a7fa8; color: #fff; font-weight: 700; border-bottom: none; }
        .jx-compte { font-family: monospace; color: #3d5473; }
        .jx-tier { color: #6c757d; font-size: 12px; }
        .jx-lettrage { font-family: monospace; font-size: 12px; background: #e7eef7; color: #1e3a5f; padding: 1px 5px; border-radius: 3px; }
        .jx-zero { color: #adb5bd; }
        .jx-desequilibre { background: #f8d7da !important; color: #842029 !important; }
    </style>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label for="journauxDateDebut" class="form-label jx-filter-label">Du</label>
                    <input
                        type="date"
                        id="journauxDateDebut"
                        class="form-control form-control-sm"
                        wire:model.live="dateDebut"
                    >
                </div>

                <div class="col-md-2">
                    <label for="journauxDateFin" class="form-label jx-filter-label">Au</label>
                    <input
                        type="date"
                        id="journauxDateFin"
                        class="form-control form-control-sm"
                        wire:model.live="dateFin"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label jx-filter-label d-block">Journaux</label>
                    <div class="d-flex flex-wrap column-gap-3 row-gap-1">
                        @foreach($this->journauxDisponibles() as $journalDisponible)
                            <div class="form-check">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    id="journal-{{ $journalDisponible['code'] }}"
                                    value="{{ $journalDisponible['code'] }}"
                                    wire:model.live="journaux"
                                >
                                <label class="form-check-label" for="journal-{{ $journalDisponible['code'] }}">
                                    {{ $journalDisponible['libelle'] }}
                                </label>
                            </div>
                        @endforeach
                        @if($journaux !== [])
                            <button type="button" class="btn btn-link btn-sm p-0" wire:click="toutSelectionner">
                                Tous
                            </button>
                        @endif
                    </div>
                </div>

                <div class="col-md-2 d-flex justify-content-end align-items-end">
                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="Exports Journaux à brancher">
                        <i class="bi bi-download me-1"></i>Exporter
                    </button>
                </div>
            </div>

            {{-- Options d'affichage : seconde ligne, cases côte à côte. --}}
            <div class="row mt-2">
                <div class="col-12 d-flex flex-wrap column-gap-4 row-gap-1">
                    <div class="form-check">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            id="journauxModeReglement"
                            wire:model.live="afficherModeReglement"
                        >
                        <label class="form-check-label jx-filter-label" for="journauxModeReglement">
                            Mode de règlement
                        </label>
                    </div>
                    <div class="form-check">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            id="journauxJustificatif"
                            wire:model.live="afficherJustificatif"
                        >
                        <label class="form-check-label jx-filter-label" for="journauxJustificatif">
                            Justificatif
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @forelse($journal['journaux'] as $bloc)
        <div class="card border-0 shadow-sm mb-3">
            <div class="jx-journal-header">
                {{ $bloc['libelle'] }}
                <span class="jx-journal-subtitle ms-2">
                    {{ count($bloc['pieces']) }} pièce(s) —
                    débit {{ $this->formatCentimes((int) $bloc['debit_centimes']) }} /
                    crédit {{ $this->formatCentimes((int) $bloc['credit_centimes']) }}
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 jx-table">
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th style="width:105px;">Date</th>
                                <th style="width:130px;">Pièce</th>
                                <th>Compte / Libellé</th>
                                <th class="text-center" style="width:80px;">Lettrage</th>
                                @if($afficherJustificatif)
                                    <th class="text-center" style="width:70px;">Justif.</th>
                                @endif
                                <th class="text-end" style="width:115px;">Débit</th>
                                <th class="text-end" style="width:115px;">Crédit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($bloc['pieces'] as $piece)
                                {{-- En-tête de pièce : l'écriture complète se lit d'un bloc. --}}
                                <tr class="jx-piece {{ $piece['equilibree'] ? '' : 'jx-desequilibre' }}">
                                    <td>{{ \Carbon\Carbon::parse($piece['date'])->format('d/m/Y') }}</td>
                                    <td>{{ $piece['numero_piece'] ?? $piece['reference'] ?? '—' }}</td>
                                    <td>
                                        {{ $piece['libelle'] }}
                                        @if($afficherModeReglement && $piece['mode_paiement'] !== null)
                                            <span class="jx-tier">· {{ $piece['mode_paiement'] }}</span>
                                        @endif
                                        @unless($piece['equilibree'])
                                            <span class="badge bg-danger ms-2">Déséquilibrée</span>
                                        @endunless
                                    </td>
                                    <td></td>
                                    @if($afficherJustificatif)
                                        <td class="text-center">
                                            @if($piece['justificatif_url'] !== null)
                                                <a href="{{ $piece['justificatif_url'] }}" target="_blank" rel="noopener" title="Ouvrir le justificatif">
                                                    <i class="bi bi-paperclip"></i>
                                                </a>
                                            @endif
                                        </td>
                                    @endif
                                    <td class="text-end">{{ $this->formatCentimes((int) $piece['debit_centimes']) }}</td>
                                    <td class="text-end">{{ $this->formatCentimes((int) $piece['credit_centimes']) }}</td>
                                </tr>

                                @foreach($piece['lignes'] as $ligne)
                                    <tr>
                                        <td></td>
                                        <td></td>
                                        <td>
                                            <span class="jx-compte">{{ $ligne['numero_compte'] }}</span>
                                            — {{ $ligne['intitule_compte'] }}
                                            @if($ligne['tiers'] !== null)
                                                <div class="jx-tier">{{ $ligne['tiers'] }}</div>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($ligne['lettrage_code'] !== null)
                                                <span class="jx-lettrage">{{ $ligne['lettrage_code'] }}</span>
                                            @else
                                                <span class="jx-zero">—</span>
                                            @endif
                                        </td>
                                        @if($afficherJustificatif)
                                            <td class="text-center">
                                                @if($ligne['justificatif_url'] !== null)
                                                    <a href="{{ $ligne['justificatif_url'] }}" target="_blank" rel="noopener" title="Ouvrir le justificatif de la ligne">
                                                        <i class="bi bi-paperclip"></i>
                                                    </a>
                                                @else
                                                    <span class="jx-zero">—</span>
                                                @endif
                                            </td>
                                        @endif
                                        <td class="text-end" data-sort="{{ $ligne['debit_centimes'] }}">
                                            <span class="{{ $ligne['debit_centimes'] === 0 ? 'jx-zero' : '' }}">
                                                {{ $this->formatCentimes((int) $ligne['debit_centimes']) }}
                                            </span>
                                        </td>
                                        <td class="text-end" data-sort="{{ $ligne['credit_centimes'] }}">
                                            <span class="{{ $ligne['credit_centimes'] === 0 ? 'jx-zero' : '' }}">
                                                {{ $this->formatCentimes((int) $ligne['credit_centimes']) }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="jx-total">
                                <td colspan="{{ $this->nombreColonnes() - 2 }}">TOTAL {{ mb_strtoupper($bloc['libelle']) }}</td>
                                <td class="text-end">{{ $this->formatCentimes((int) $bloc['debit_centimes']) }}</td>
                                <td class="text-end">{{ $this->formatCentimes((int) $bloc['credit_centimes']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-muted py-5">
                Aucune écriture sur la période et les journaux retenus.
            </div>
        </div>
    @endforelse

    @if($journal['journaux'] !== [])
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex justify-content-between align-items-center">
                <strong>TOTAL GÉNÉRAL — {{ $journal['totaux']['nb_pieces'] }} pièce(s)</strong>
                <div>
                    <span class="me-3">Débit : <strong>{{ $this->formatCentimes((int) $journal['totaux']['debit_centimes']) }}</strong></span>
                    <span>Crédit : <strong>{{ $this->formatCentimes((int) $journal['totaux']['credit_centimes']) }}</strong></span>
                </div>
            </div>
        </div>
    @endif
</div>
