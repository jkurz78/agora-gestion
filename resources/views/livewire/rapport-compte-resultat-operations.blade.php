<div>
    {{-- Barre de filtres --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                {{-- Dropdown hiérarchique --}}
                <div x-data="{
                    selectedIds: @entangle('selectedOperationIds').live,
                    open: false,
                    tree: @js($operationTree),

                    init() {
                        if (this.selectedIds.length === 0) {
                            this.$nextTick(() => { this.open = true; });
                        }
                    },

                    toggleOp(id) {
                        const idx = this.selectedIds.indexOf(id);
                        if (idx > -1) {
                            this.selectedIds = this.selectedIds.filter(i => i !== id);
                        } else {
                            this.selectedIds = [...this.selectedIds, id];
                        }
                    },

                    toggleGroup(opIds) {
                        const allIn = opIds.every(id => this.selectedIds.includes(id));
                        if (allIn) {
                            this.selectedIds = this.selectedIds.filter(id => !opIds.includes(id));
                        } else {
                            const newIds = [...this.selectedIds];
                            opIds.forEach(id => { if (!newIds.includes(id)) newIds.push(id); });
                            this.selectedIds = newIds;
                        }
                    },

                    groupState(opIds) {
                        const count = opIds.filter(id => this.selectedIds.includes(id)).length;
                        if (count === 0) return 'none';
                        if (count === opIds.length) return 'all';
                        return 'partial';
                    },

                    get label() {
                        const n = this.selectedIds.length;
                        if (n === 0) return 'Sélectionnez des opérations...';
                        if (n === 1) {
                            for (const sc of this.tree) {
                                for (const t of sc.types) {
                                    for (const o of t.operations) {
                                        if (o.id === this.selectedIds[0]) return o.nom;
                                    }
                                }
                            }
                        }
                        return n + ' opérations sélectionnées';
                    },
                }" class="dropdown" @click.outside="open = false">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                            @click="open = !open" x-text="label" style="min-width:220px;text-align:left;"></button>
                    <div class="dropdown-menu p-2" :class="{ show: open }"
                         style="min-width:320px;max-height:400px;overflow-y:auto;">
                        <template x-for="sc in tree" :key="sc.id">
                            <div class="mb-2">
                                {{-- Niveau sous-catégorie --}}
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input"
                                           :checked="groupState(sc.types.flatMap(t => t.operations.map(o => o.id))) === 'all'"
                                           :indeterminate="groupState(sc.types.flatMap(t => t.operations.map(o => o.id))) === 'partial'"
                                           @change="toggleGroup(sc.types.flatMap(t => t.operations.map(o => o.id)))">
                                    <label class="form-check-label fw-bold text-muted small" x-text="sc.nom"></label>
                                </div>
                                <template x-for="type in sc.types" :key="type.id">
                                    <div class="ms-3">
                                        {{-- Niveau type opération --}}
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input"
                                                   :checked="groupState(type.operations.map(o => o.id)) === 'all'"
                                                   :indeterminate="groupState(type.operations.map(o => o.id)) === 'partial'"
                                                   @change="toggleGroup(type.operations.map(o => o.id))">
                                            <label class="form-check-label fw-semibold small" x-text="type.nom"></label>
                                        </div>
                                        {{-- Niveau opérations --}}
                                        <template x-for="op in type.operations" :key="op.id">
                                            <div class="form-check ms-3">
                                                <input type="checkbox" class="form-check-input"
                                                       :checked="selectedIds.includes(op.id)"
                                                       @change="toggleOp(op.id)">
                                                <label class="form-check-label small" x-text="op.nom"></label>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Toggles --}}
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" wire:model.live="parSeances" class="form-check-input" id="toggleSeances">
                    <label class="form-check-label small" for="toggleSeances">S&eacute;ances en colonnes</label>
                </div>
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" wire:model.live="parTiers" class="form-check-input" id="toggleTiers">
                    <label class="form-check-label small" for="toggleTiers">Tiers en lignes</label>
                </div>
                {{-- Export dropdown --}}
                @if (! empty($selectedOperationIds))
                <div class="ms-auto">
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
                @endif
            </div>
        </div>
    </div>

    {{-- Contenu du rapport --}}
    @if (! $hasSelection)
        <p class="text-muted text-center py-4">S&eacute;lectionnez au moins une op&eacute;ration pour afficher le rapport.</p>
    @else
        @foreach ([
            ['data' => $charges, 'label' => 'DÉPENSES', 'totalMontant' => $totalCharges],
            ['data' => $produits, 'label' => 'RECETTES', 'totalMontant' => $totalProduits],
        ] as $section)
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                    <tbody>
                        {{-- En-tête colonnes --}}
                        @if ($parSeances)
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                @foreach ($seances as $s)
                                    <td class="text-end" style="width:90px;font-size:11px;opacity:.85;padding:4px 8px;">
                                        {{ $s === 0 ? 'Hors séances' : 'S'.$s }}
                                    </td>
                                @endforeach
                                <td class="text-end" style="width:90px;font-size:11px;opacity:.85;padding:4px 8px;">Total</td>
                            </tr>
                        @else
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                <td class="text-end" style="width:130px;font-size:12px;opacity:.85;">Montant</td>
                            </tr>
                        @endif
                        {{-- Titre section --}}
                        <tr style="background:#3d5473;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="{{ $parSeances ? count($seances) + 3 : 3 }}" style="padding:4px 12px 10px;">
                                {{ $section['label'] }}
                            </td>
                        </tr>

                        @foreach ($section['data'] as $cat)
                            @php
                                $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) =>
                                    ($parSeances ? ($sc['total'] ?? 0) : ($sc['montant'] ?? 0)) > 0
                                );
                            @endphp
                            @if (! $scVisibles->isEmpty())
                                {{-- Ligne catégorie --}}
                                <tr style="background:#dce6f0;">
                                    <td></td>
                                    <td style="font-weight:600;color:#1e3a5f;padding:7px 12px;">{{ $cat['label'] }}</td>
                                    @if ($parSeances)
                                        @foreach ($seances as $s)
                                            <td class="text-end fw-bold" style="padding:7px 8px;">
                                                @if (($cat['seances'][$s] ?? 0) > 0)
                                                    {{ number_format($cat['seances'][$s], 2, ',', ' ') }} &euro;
                                                @else
                                                    &mdash;
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="text-end fw-bold" style="padding:7px 8px;">{{ number_format($cat['total'], 2, ',', ' ') }} &euro;</td>
                                    @else
                                        <td class="text-end fw-bold" style="padding:7px 12px;">{{ number_format($cat['montant'], 2, ',', ' ') }} &euro;</td>
                                    @endif
                                </tr>

                                @foreach ($scVisibles as $sc)
                                    {{-- Ligne sous-catégorie --}}
                                    <tr style="background:#f7f9fc;">
                                        <td></td>
                                        <td style="padding:5px 12px 5px 32px;color:#444;">{{ $sc['label'] }}</td>
                                        @if ($parSeances)
                                            @foreach ($seances as $s)
                                                <td class="text-end" style="padding:5px 8px;color:#444;">
                                                    @if (($sc['seances'][$s] ?? 0) > 0)
                                                        {{ number_format($sc['seances'][$s], 2, ',', ' ') }} &euro;
                                                    @else
                                                        &mdash;
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="text-end fw-bold" style="padding:5px 8px;">{{ number_format($sc['total'], 2, ',', ' ') }} &euro;</td>
                                        @else
                                            <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['montant'], 2, ',', ' ') }} &euro;</td>
                                        @endif
                                    </tr>

                                    {{-- Lignes tiers (si activé) --}}
                                    @if ($parTiers && ! empty($sc['tiers']))
                                        @foreach ($sc['tiers'] as $t)
                                            @if (($parSeances ? ($t['total'] ?? 0) : ($t['montant'] ?? 0)) > 0)
                                            <tr style="background:#fff;">
                                                <td></td>
                                                <td style="padding:4px 12px 4px 52px;color:#666;font-size:12px;">
                                                    @if ($t['type'] === 'entreprise')
                                                        <i class="bi bi-building text-muted" style="font-size:.65rem"></i>
                                                    @elseif ($t['type'] === 'particulier')
                                                        <i class="bi bi-person text-muted" style="font-size:.65rem"></i>
                                                    @endif
                                                    @if ($t['tiers_id'] === 0)
                                                        <em>{{ $t['label'] }}</em>
                                                    @else
                                                        {{ $t['label'] }}
                                                    @endif
                                                </td>
                                                @if ($parSeances)
                                                    @foreach ($seances as $s)
                                                        <td class="text-end" style="padding:4px 8px;color:#888;font-size:12px;">
                                                            @if (($t['seances'][$s] ?? 0) > 0)
                                                                {{ number_format($t['seances'][$s], 2, ',', ' ') }} &euro;
                                                            @else
                                                                &mdash;
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                    <td class="text-end" style="padding:4px 8px;color:#666;font-size:12px;">{{ number_format($t['total'], 2, ',', ' ') }} &euro;</td>
                                                @else
                                                    <td class="text-end" style="padding:4px 12px;color:#666;font-size:12px;">{{ number_format($t['montant'], 2, ',', ' ') }} &euro;</td>
                                                @endif
                                            </tr>
                                            @endif
                                        @endforeach
                                    @endif
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Ligne total section --}}
                        @php
                            $totalSectionSeances = [];
                            if ($parSeances) {
                                $totalSectionSeances = array_fill_keys($seances, 0.0);
                                foreach ($section['data'] as $cat) {
                                    foreach ($seances as $s) {
                                        $totalSectionSeances[$s] += $cat['seances'][$s] ?? 0.0;
                                    }
                                }
                            }
                        @endphp
                        <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="2" style="padding:9px 12px;">TOTAL {{ $section['label'] }}</td>
                            @if ($parSeances)
                                @foreach ($seances as $s)
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($totalSectionSeances[$s], 2, ',', ' ') }} &euro;</td>
                                @endforeach
                                <td class="text-end" style="padding:9px 8px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                            @else
                                <td class="text-end" style="padding:9px 12px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                            @endif
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach

        {{-- Barre résultat net --}}
        <div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
             style="background:{{ $resultatNet >= 0 ? '#2E7D32' : '#B5453A' }};color:#fff;font-size:1.1rem;font-weight:700;">
            <span>{{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
            <span>{{ number_format(abs($resultatNet), 2, ',', ' ') }} &euro;</span>
        </div>
    @endif
</div>
