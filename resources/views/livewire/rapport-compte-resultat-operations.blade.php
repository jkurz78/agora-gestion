<div>
    {{-- Filtre operations --}}
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
                               value="{{ $op->id }}" id="op-{{ $op->id }}" class="form-check-input">
                        <label for="op-{{ $op->id }}" class="form-check-label">{{ $op->nom }}</label>
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
        <p class="text-muted text-center py-4">S&eacute;lectionnez au moins une op&eacute;ration pour afficher le rapport.</p>
    @else
        @foreach ([['data' => $charges, 'label' => 'DEPENSES', 'total' => $totalChargesN],
                   ['data' => $produits, 'label' => 'RECETTES', 'total' => $totalProduitsN]] as $section)
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                    <tbody>
                        <tr style="background:#3d5473;color:#fff;">
                            <td style="width:20px;"></td>
                            <td></td>
                            <td class="text-end" style="width:130px;font-size:12px;opacity:.85;">Montant</td>
                        </tr>
                        <tr style="background:#3d5473;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="3" style="padding:4px 12px 10px;">{{ $section['label'] }}</td>
                        </tr>

                        @foreach ($section['data'] as $cat)
                            @php
                                $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) => $sc['montant'] > 0);
                            @endphp
                            @if (! $scVisibles->isEmpty())
                            <tr style="background:#dce6f0;">
                                <td></td>
                                <td style="font-weight:600;color:#1e3a5f;padding:7px 12px;">{{ $cat['label'] }}</td>
                                <td class="text-end fw-bold" style="padding:7px 12px;">{{ number_format($cat['montant'], 2, ',', ' ') }} &euro;</td>
                            </tr>
                            @foreach ($scVisibles as $sc)
                            <tr style="background:#f7f9fc;">
                                <td></td>
                                <td style="padding:5px 12px 5px 32px;color:#444;">{{ $sc['label'] }}</td>
                                <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['montant'], 2, ',', ' ') }} &euro;</td>
                            </tr>
                            @endforeach
                            @endif
                        @endforeach

                        <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="2" style="padding:9px 12px;">TOTAL {{ $section['label'] }}</td>
                            <td class="text-end" style="padding:9px 12px;">{{ number_format($section['total'], 2, ',', ' ') }} &euro;</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach

        <div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
             style="background:{{ $resultatNet >= 0 ? '#2E7D32' : '#B5453A' }};color:#fff;font-size:1.1rem;font-weight:700;">
            <span>{{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
            <span>{{ number_format(abs($resultatNet), 2, ',', ' ') }} &euro;</span>
        </div>
    @endif
</div>
