<div>
    {{-- Filtre opérations --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Filtrer par type</label>
                <select wire:model.live="filterTypeId" class="form-select form-select-sm" style="max-width: 250px;">
                    <option value="">Tous les types</option>
                    @foreach($typeOperations as $type)
                        <option value="{{ $type->id }}">{{ $type->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                @foreach ($operations as $op)
                    <div class="form-check">
                        <input type="checkbox" wire:model.live="selectedOperationIds"
                               value="{{ $op->id }}" id="ops-{{ $op->id }}" class="form-check-input">
                        <label for="ops-{{ $op->id }}" class="form-check-label">{{ $op->nom }}</label>
                    </div>
                @endforeach
                <button wire:click="exportCsv" class="btn btn-outline-secondary btn-sm ms-auto"
                        {{ $hasSelection ? '' : 'disabled' }}>
                    <i class="bi bi-download"></i> Exporter CSV
                </button>
            </div>
        </div>
    </div>

    @if (! $hasSelection)
        <p class="text-muted text-center py-4">Sélectionnez au moins une opération pour afficher le rapport.</p>
    @else
        @php $nbCols = count($seances) + 3; @endphp

        @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'total' => $totalChargesN],
                   ['data' => $produits, 'label' => 'RECETTES', 'total' => $totalProduitsN]] as $section)
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                    <tbody>
                        {{-- En-tête colonnes --}}
                        <tr style="background:#3d5473;color:#fff;">
                            <td style="width:20px;"></td>
                            <td></td>
                            @foreach ($seances as $s)
                                <td class="text-end" style="width:90px;font-size:12px;font-weight:400;opacity:.85;">{{ $s === 0 ? 'Hors séance' : 'Séance '.$s }}</td>
                            @endforeach
                            <td class="text-end" style="width:100px;font-size:12px;font-weight:400;opacity:.85;">Total</td>
                        </tr>
                        <tr style="background:#3d5473;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="{{ $nbCols }}" style="padding:4px 12px 10px;">{{ $section['label'] }}</td>
                        </tr>

                        @foreach ($section['data'] as $cat)
                            @php
                                $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) => $sc['total'] > 0);
                            @endphp
                            @if (! $scVisibles->isEmpty())
                            <tr style="background:#dce6f0;">
                                <td></td>
                                <td style="font-weight:600;color:#1e3a5f;padding:7px 12px;">{{ $cat['label'] }}</td>
                                @foreach ($seances as $s)
                                    <td class="text-end fw-semibold" style="padding:7px 12px;">{{ number_format($cat['seances'][$s] ?? 0, 2, ',', ' ') }} &euro;</td>
                                @endforeach
                                <td class="text-end fw-bold" style="padding:7px 12px;">{{ number_format($cat['total'], 2, ',', ' ') }} &euro;</td>
                            </tr>
                            @foreach ($scVisibles as $sc)
                            <tr style="background:#f7f9fc;">
                                <td></td>
                                <td style="padding:5px 12px 5px 32px;color:#444;">{{ $sc['label'] }}</td>
                                @foreach ($seances as $s)
                                    <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['seances'][$s] ?? 0, 2, ',', ' ') }} &euro;</td>
                                @endforeach
                                <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['total'], 2, ',', ' ') }} &euro;</td>
                            </tr>
                            @endforeach
                            @endif
                        @endforeach

                        <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="2" style="padding:9px 12px;">TOTAL {{ $section['label'] }}</td>
                            @foreach ($seances as $s)
                                <td class="text-end" style="padding:9px 12px;color:#d0e4f7;">—</td>
                            @endforeach
                            <td class="text-end" style="padding:9px 12px;">{{ number_format($section['total'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach

        <div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
             style="background:{{ $resultatNet >= 0 ? '#198754' : '#dc3545' }};color:#fff;font-size:1.1rem;font-weight:700;">
            <span>{{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
            <span>{{ number_format(abs($resultatNet), 2, ',', ' ') }} &euro;</span>
        </div>
    @endif
</div>
