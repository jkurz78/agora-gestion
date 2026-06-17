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
                            @click="open = !open" x-text="label"></button>
                    <div class="dropdown-menu p-3" :class="{'show': open}" style="min-width:350px;max-height:400px;overflow-y:auto;">
                        <template x-for="sc in tree" :key="sc.id">
                            <div class="mb-2">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input"
                                           :checked="groupState(sc.types.flatMap(t => t.operations.map(o => o.id))) === 'all'"
                                           :indeterminate="groupState(sc.types.flatMap(t => t.operations.map(o => o.id))) === 'partial'"
                                           @change="toggleGroup(sc.types.flatMap(t => t.operations.map(o => o.id)))">
                                    <label class="form-check-label small fw-bold text-muted" x-text="sc.nom"></label>
                                </div>
                                <template x-for="type in sc.types" :key="type.id">
                                    <div class="ms-2 mb-1">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input"
                                                   :checked="groupState(type.operations.map(o => o.id)) === 'all'"
                                                   :indeterminate="groupState(type.operations.map(o => o.id)) === 'partial'"
                                                   @change="toggleGroup(type.operations.map(o => o.id))">
                                            <label class="form-check-label small fw-semibold" x-text="type.nom"></label>
                                        </div>
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

                {{-- Dropdown Mode --}}
                <select wire:model.live="mode" class="form-select form-select-sm" style="width:auto;">
                    <option value="realise">Réalisé</option>
                    <option value="projection">Projection</option>
                </select>

                {{-- Toggles --}}
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" wire:model.live="parSeances" class="form-check-input" id="toggleSeances">
                    <label class="form-check-label small" for="toggleSeances">S&eacute;ances en colonnes</label>
                </div>
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" wire:model.live="parTiers" class="form-check-input" id="toggleTiers">
                    <label class="form-check-label small" for="toggleTiers">Tiers en lignes</label>
                </div>
                @if (count($selectedOperationIds) > 1)
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" wire:model.live="parOperations" class="form-check-input" id="toggleOperations">
                    <label class="form-check-label small" for="toggleOperations">Op&eacute;rations en colonnes</label>
                </div>
                @endif

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
        @php
            // ---------------------------------------------------------------
            // Helpers prévisionnel / projection
            // ---------------------------------------------------------------

            $indexPrevisions = function (array $hierarchy): array {
                $idx = ['sc' => [], 'tiers' => []];
                foreach ($hierarchy as $cat) {
                    foreach ($cat['sous_categories'] as $sc) {
                        $scId = (int) ($sc['sous_categorie_id'] ?? 0);
                        $idx['sc'][$scId] = (float) ($sc['montant'] ?? 0);
                        foreach ($sc['tiers'] ?? [] as $t) {
                            $tId = (int) ($t['tiers_id'] ?? 0);
                            $idx['tiers'][$scId][$tId] = (float) ($t['montant'] ?? 0);
                        }
                    }
                }
                return $idx;
            };

            $idxPrevCharges  = $indexPrevisions($previsionsCharges);
            $idxPrevProduits = $indexPrevisions($previsionsProduits);

            // ---------------------------------------------------------------
            // Calcul du colspan total pour les lignes pleine-largeur
            // ---------------------------------------------------------------
            $combinedMode = $parSeances && $parOperations;
            $nbDataCols = 1;
            if ($combinedMode) {
                $nbDataCols = 0;
                foreach ($seancesParOperation as $opId => $opSeances) {
                    $nbDataCols += count($opSeances) + 1;
                }
                $nbDataCols += 1; // TOTAL column
            } elseif ($parSeances) {
                $nbDataCols = count($seances) + 1;
            } elseif ($parOperations) {
                $nbOps = count($operationNames);
                $nbDataCols = $nbOps + 1;
            } elseif ($mode === 'projection') {
                $nbDataCols = 1;
            }
            $totalColspan = 2 + $nbDataCols;
        @endphp

        @php $projectedSectionTotals = []; $projectedOpSectionTotals = []; @endphp
        @foreach ([
            ['data' => $charges, 'prevDisplay' => $previsionsCharges, 'label' => 'DÉPENSES', 'totalMontant' => $totalCharges, 'proj' => $projCharges],
            ['data' => $produits, 'prevDisplay' => $previsionsProduits, 'label' => 'RECETTES', 'totalMontant' => $totalProduits, 'proj' => $projProduits],
        ] as $section)
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                    <tbody>
                        {{-- En-tête colonnes --}}
                        @if ($combinedMode)
                            {{-- Ligne 1 : noms des opérations --}}
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                @foreach ($operationNames as $opId => $opNom)
                                    @php $opColspan = count($seancesParOperation[$opId] ?? []) + 1; @endphp
                                    <td colspan="{{ $opColspan }}" class="text-center" style="font-size:11px;opacity:.85;padding:4px 4px;border-left:1px solid rgba(255,255,255,.2);" title="{{ $opNom }}">
                                        {{ \Illuminate\Support\Str::limit($opNom, 25) }}
                                    </td>
                                @endforeach
                                <td rowspan="2" class="text-end align-bottom" style="width:70px;font-size:11px;opacity:.85;padding:4px 8px;">Total</td>
                            </tr>
                            {{-- Ligne 2 : séances par opération --}}
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                @foreach ($operationNames as $opId => $opNom)
                                    @foreach ($seancesParOperation[$opId] ?? [] as $s)
                                        <td class="text-end" style="width:60px;font-size:10px;opacity:.75;padding:3px 5px;">
                                            {{ $s === 0 ? 'H.S.' : 'S'.$s }}
                                        </td>
                                    @endforeach
                                    <td class="text-end" style="width:60px;font-size:10px;opacity:.85;padding:3px 5px;border-left:1px solid rgba(255,255,255,.15);font-weight:600;">
                                        Tot.
                                    </td>
                                @endforeach
                            </tr>
                        @elseif ($parSeances)
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
                        @elseif ($parOperations)
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                @foreach ($operationNames as $opId => $opNom)
                                    <td class="text-end" style="width:100px;font-size:11px;opacity:.85;padding:4px 8px;" title="{{ $opNom }}">
                                        {{ \Illuminate\Support\Str::limit($opNom, 20) }}
                                    </td>
                                @endforeach
                                <td class="text-end" style="width:100px;font-size:11px;opacity:.85;padding:4px 8px;">Total</td>
                            </tr>
                        @else
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                @if ($mode === 'projection')
                                    <td class="text-end" style="width:130px;font-size:12px;opacity:.85;">Projeté</td>
                                @else
                                    <td class="text-end" style="width:130px;font-size:12px;opacity:.85;">Montant</td>
                                @endif
                            </tr>
                        @endif

                        {{-- Titre section --}}
                        <tr style="background:#3d5473;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="{{ $totalColspan }}" style="padding:4px 12px 10px;">
                                {{ $section['label'] }}
                            </td>
                        </tr>

                        @php
                            $sectionIdx = $section['label'] === 'DÉPENSES' ? $idxPrevCharges : $idxPrevProduits;
                            $isDepenses = $section['label'] === 'DÉPENSES';
                        @endphp

                        @foreach ($section['data'] as $cat)
                            @php
                                $scVisibles = collect($cat['sous_categories'])->filter(function ($sc) use ($mode, $sectionIdx) {
                                    $realise = (float) ($sc['montant'] ?? 0);
                                    if ($realise > 0) {
                                        return true;
                                    }
                                    if ($mode === 'realise') {
                                        return false;
                                    }
                                    return ((float) ($sectionIdx['sc'][$sc['sous_categorie_id']] ?? 0)) > 0;
                                });
                            @endphp
                            @if (! $scVisibles->isEmpty())
                                {{-- Ligne catégorie --}}
                                <tr style="background:#dce6f0;">
                                    <td></td>
                                    <td style="font-weight:600;color:#1e3a5f;padding:7px 12px;">{{ $cat['label'] }}</td>
                                    @if ($combinedMode)
                                        @php $catId = (int) ($cat['categorie_id'] ?? 0); @endphp
                                        @foreach ($operationNames as $opId => $opNom)
                                            @foreach ($seancesParOperation[$opId] ?? [] as $s)
                                                @php
                                                    $val = ($mode === 'projection' && $section['proj'])
                                                        ? collect($cat['sous_categories'])->sum(fn ($__sc) => (float) ($section['proj']->byScSeanceOp()[(int) ($__sc['sous_categorie_id'] ?? 0)][$s][$opId] ?? 0))
                                                        : (float) ($cat['seance_operations'][$s][$opId] ?? 0);
                                                @endphp
                                                <td class="text-end fw-bold" style="padding:5px 4px;font-size:11px;">{{ $val > 0 ? number_format($val, 2, ',', ' ').' €' : '—' }}</td>
                                            @endforeach
                                            @php
                                                $opTot = ($mode === 'projection' && $section['proj'])
                                                    ? (float) ($section['proj']->byCatOp()[$catId][$opId] ?? 0)
                                                    : (float) ($cat['operations'][$opId] ?? 0);
                                            @endphp
                                            <td class="text-end fw-bold" style="padding:5px 4px;font-size:11px;border-left:1px solid #c0cfe0;">{{ $opTot > 0 ? number_format($opTot, 2, ',', ' ').' €' : '—' }}</td>
                                        @endforeach
                                        @php
                                            $grandTot = ($mode === 'projection' && $section['proj'])
                                                ? (float) ($section['proj']->byCat()[$catId] ?? 0)
                                                : (float) ($cat['montant'] ?? 0);
                                        @endphp
                                        <td class="text-end fw-bold" style="padding:5px 8px;">{{ number_format($grandTot, 2, ',', ' ') }} &euro;</td>
                                    @elseif ($parSeances)
                                        @foreach ($seances as $s)
                                            @if ($mode === 'projection')
                                                @php
                                                    $projCatSeance = 0.0;
                                                    foreach ($cat['sous_categories'] as $__sc) {
                                                        $__scId = (int) ($__sc['sous_categorie_id'] ?? 0);
                                                        $projCatSeance += (float) ($section['proj']->byScSeance()[$__scId][$s] ?? 0);
                                                    }
                                                @endphp
                                                <td class="text-end fw-bold" style="padding:7px 8px;">
                                                    @if ($projCatSeance > 0)
                                                        {{ number_format($projCatSeance, 2, ',', ' ') }} &euro;
                                                    @else
                                                        &mdash;
                                                    @endif
                                                </td>
                                            @else
                                                <td class="text-end fw-bold" style="padding:7px 8px;">
                                                    @if (($cat['seances'][$s] ?? 0) > 0)
                                                        {{ number_format($cat['seances'][$s], 2, ',', ' ') }} &euro;
                                                    @else
                                                        &mdash;
                                                    @endif
                                                </td>
                                            @endif
                                        @endforeach
                                        {{-- Total séances --}}
                                        @if ($mode === 'projection')
                                            @php $projCatTotal = (float) ($section['proj']->byCat()[$cat['categorie_id']] ?? 0); @endphp
                                            <td class="text-end fw-bold" style="padding:7px 8px;">
                                                {{ number_format($projCatTotal, 2, ',', ' ') }} &euro;
                                            </td>
                                        @else
                                            <td class="text-end fw-bold" style="padding:7px 8px;">{{ number_format($cat['montant'], 2, ',', ' ') }} &euro;</td>
                                        @endif
                                    @elseif ($parOperations)
                                        @foreach ($operationNames as $opId => $opNom)
                                            @if ($mode === 'projection')
                                                @php $projVal = (float) ($section['proj']->byCatOp()[$cat['categorie_id']][$opId] ?? 0); @endphp
                                                <td class="text-end fw-bold" style="padding:7px 8px;font-size:12px;">
                                                    {{ $projVal > 0 ? number_format($projVal, 2, ',', ' ').' €' : '—' }}
                                                </td>
                                            @else
                                                @php $opRealise = (float) ($cat['operations'][$opId] ?? 0); @endphp
                                                <td class="text-end fw-bold" style="padding:7px 8px;font-size:12px;">
                                                    {{ $opRealise > 0 ? number_format($opRealise, 2, ',', ' ').' €' : '—' }}
                                                </td>
                                            @endif
                                        @endforeach
                                        {{-- Total opérations --}}
                                        @if ($mode === 'projection')
                                            @php $projCatTotal = (float) ($section['proj']->byCat()[$cat['categorie_id']] ?? 0); @endphp
                                            <td class="text-end fw-bold" style="padding:7px 8px;">{{ number_format($projCatTotal, 2, ',', ' ') }} &euro;</td>
                                        @else
                                            <td class="text-end fw-bold" style="padding:7px 8px;">{{ number_format($cat['montant'], 2, ',', ' ') }} &euro;</td>
                                        @endif
                                    @else
                                        @if ($mode === 'projection')
                                            @php $projCat = (float) ($section['proj']->byCat()[$cat['categorie_id']] ?? 0); @endphp
                                            <td class="text-end fw-bold" style="padding:7px 12px;">{{ number_format($projCat, 2, ',', ' ') }} &euro;</td>
                                        @else
                                            <td class="text-end fw-bold" style="padding:7px 12px;">{{ number_format($cat['montant'], 2, ',', ' ') }} &euro;</td>
                                        @endif
                                    @endif
                                </tr>

                                @foreach ($scVisibles as $sc)
                                    {{-- Ligne sous-catégorie --}}
                                    <tr style="background:#f7f9fc;">
                                        <td></td>
                                        <td style="padding:5px 12px 5px 32px;color:#444;">{{ $sc['label'] }}</td>
                                        @if ($combinedMode)
                                            @php $__scIdC = (int) ($sc['sous_categorie_id'] ?? 0); @endphp
                                            @foreach ($operationNames as $opId => $opNom)
                                                @foreach ($seancesParOperation[$opId] ?? [] as $s)
                                                    @php
                                                        $val = ($mode === 'projection' && $section['proj'])
                                                            ? (float) ($section['proj']->byScSeanceOp()[$__scIdC][$s][$opId] ?? 0)
                                                            : (float) ($sc['seance_operations'][$s][$opId] ?? 0);
                                                    @endphp
                                                    <td class="text-end" style="padding:4px 4px;font-size:11px;color:#444;">{{ $val > 0 ? number_format($val, 2, ',', ' ').' €' : '—' }}</td>
                                                @endforeach
                                                @php
                                                    $scOpTot = ($mode === 'projection' && $section['proj'])
                                                        ? (float) ($section['proj']->byScOp()[$__scIdC][$opId] ?? 0)
                                                        : (float) ($sc['operations'][$opId] ?? 0);
                                                @endphp
                                                <td class="text-end" style="padding:4px 4px;font-size:11px;color:#444;border-left:1px solid #e5e5e5;">{{ $scOpTot > 0 ? number_format($scOpTot, 2, ',', ' ').' €' : '—' }}</td>
                                            @endforeach
                                            @php
                                                $scGrand = ($mode === 'projection' && $section['proj'])
                                                    ? (float) ($section['proj']->bySc()[$__scIdC] ?? 0)
                                                    : (float) ($sc['montant'] ?? 0);
                                            @endphp
                                            <td class="text-end fw-bold" style="padding:4px 8px;color:#444;">{{ number_format($scGrand, 2, ',', ' ') }} &euro;</td>
                                        @elseif ($parSeances)
                                            @foreach ($seances as $s)
                                                @if ($mode === 'projection')
                                                    @php
                                                        $projScSeanceVal = (float) ($section['proj']->byScSeance()[$sc['sous_categorie_id']][$s] ?? 0);
                                                        $rVal = (float) ($sc['seances'][$s] ?? 0);
                                                        $projColor = ($rVal <= 0 && $projScSeanceVal > 0) ? '#1565C0' : 'inherit';
                                                    @endphp
                                                    <td class="text-end" style="padding:5px 8px;color:{{ $projColor }};">
                                                        @if ($projScSeanceVal > 0)
                                                            {{ number_format($projScSeanceVal, 2, ',', ' ') }} &euro;
                                                        @else
                                                            &mdash;
                                                        @endif
                                                    </td>
                                                @else
                                                    <td class="text-end" style="padding:5px 8px;color:#444;">
                                                        @if (($sc['seances'][$s] ?? 0) > 0)
                                                            {{ number_format($sc['seances'][$s], 2, ',', ' ') }} &euro;
                                                        @else
                                                            &mdash;
                                                        @endif
                                                    </td>
                                                @endif
                                            @endforeach
                                            {{-- Total sc séances --}}
                                            @if ($mode === 'projection')
                                                @php $projScTotal = (float) ($section['proj']->bySc()[$sc['sous_categorie_id']] ?? 0); @endphp
                                                <td class="text-end fw-bold" style="padding:5px 8px;">{{ number_format($projScTotal, 2, ',', ' ') }} &euro;</td>
                                            @else
                                                <td class="text-end fw-bold" style="padding:5px 8px;">{{ number_format($sc['montant'], 2, ',', ' ') }} &euro;</td>
                                            @endif
                                        @elseif ($parOperations)
                                            @foreach ($operationNames as $opId => $opNom)
                                                @if ($mode === 'projection')
                                                    @php $projVal = (float) ($section['proj']->byScOp()[$sc['sous_categorie_id']][$opId] ?? 0); @endphp
                                                    <td class="text-end" style="padding:5px 8px;font-size:12px;color:#444;">
                                                        {{ $projVal > 0 ? number_format($projVal, 2, ',', ' ').' €' : '—' }}
                                                    </td>
                                                @else
                                                    @php $opRealise = (float) ($sc['operations'][$opId] ?? 0); @endphp
                                                    <td class="text-end" style="padding:5px 8px;font-size:12px;color:#444;">
                                                        {{ $opRealise > 0 ? number_format($opRealise, 2, ',', ' ').' €' : '—' }}
                                                    </td>
                                                @endif
                                            @endforeach
                                            {{-- Total sc opérations --}}
                                            @if ($mode === 'projection')
                                                @php $projScTotal = (float) ($section['proj']->bySc()[$sc['sous_categorie_id']] ?? 0); @endphp
                                                <td class="text-end fw-bold" style="padding:5px 8px;color:#444;">{{ number_format($projScTotal, 2, ',', ' ') }} &euro;</td>
                                            @else
                                                <td class="text-end fw-bold" style="padding:5px 8px;color:#444;">{{ number_format($sc['montant'], 2, ',', ' ') }} &euro;</td>
                                            @endif
                                        @else
                                            @if ($mode === 'projection')
                                                @php $projSc = (float) ($section['proj']->bySc()[$sc['sous_categorie_id']] ?? 0); @endphp
                                                <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($projSc, 2, ',', ' ') }} &euro;</td>
                                            @else
                                                <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['montant'], 2, ',', ' ') }} &euro;</td>
                                            @endif
                                        @endif
                                    </tr>

                                    {{-- Lignes tiers --}}
                                    @if ($parTiers && ! empty($sc['tiers']))
                                        @foreach ($sc['tiers'] as $t)
                                            @php
                                                $tRealise = (float) ($t['montant'] ?? 0);
                                                $tPrev = (float) ($sectionIdx['tiers'][$sc['sous_categorie_id']][$t['tiers_id'] ?? -1] ?? 0);
                                                $tVisible = $tRealise > 0 || ($mode !== 'realise' && $tPrev > 0);
                                            @endphp
                                            @if ($tVisible)
                                            <tr style="background:#fff;">
                                                <td></td>
                                                <td style="padding:4px 12px 4px 52px;color:#666;font-size:12px;">
                                                    @if (($t['type'] ?? null) === 'entreprise')
                                                        <i class="bi bi-building text-muted" style="font-size:.65rem"></i>
                                                    @elseif (($t['type'] ?? null) === 'particulier')
                                                        <i class="bi bi-person text-muted" style="font-size:.65rem"></i>
                                                    @endif
                                                    @if (($t['tiers_id'] ?? null) === 0)
                                                        <em>{{ $t['label'] }}</em>
                                                    @else
                                                        {{ $t['label'] }}
                                                    @endif
                                                </td>
                                                @if ($combinedMode)
                                                    @php
                                                        $__scIdTC = (int) ($sc['sous_categorie_id'] ?? 0);
                                                        $__tIdTC = (int) ($t['tiers_id'] ?? 0);
                                                        $projTSO = ($mode === 'projection' && $section['proj'])
                                                            ? ($section['proj']->byScTiersSeanceOp($__scIdTC)[$__tIdTC] ?? [])
                                                            : [];
                                                    @endphp
                                                    @foreach ($operationNames as $opId => $opNom)
                                                        @foreach ($seancesParOperation[$opId] ?? [] as $s)
                                                            @php
                                                                $tSOVal = ($mode === 'projection' && $section['proj'])
                                                                    ? (float) ($projTSO[$s][$opId] ?? 0)
                                                                    : (float) ($t['seance_operations'][$s][$opId] ?? 0);
                                                            @endphp
                                                            <td class="text-end" style="padding:3px 4px;color:#888;font-size:10px;">{{ $tSOVal > 0 ? number_format($tSOVal, 2, ',', ' ').' €' : '—' }}</td>
                                                        @endforeach
                                                        @php
                                                            $tOpTot = ($mode === 'projection' && $section['proj'])
                                                                ? (float) ($section['proj']->byScTiersOp($__scIdTC)[$__tIdTC][$opId] ?? 0)
                                                                : (float) ($t['operations'][$opId] ?? 0);
                                                        @endphp
                                                        <td class="text-end" style="padding:3px 4px;color:#888;font-size:10px;border-left:1px solid #f0f0f0;">{{ $tOpTot > 0 ? number_format($tOpTot, 2, ',', ' ').' €' : '—' }}</td>
                                                    @endforeach
                                                    @php
                                                        $tGrand = ($mode === 'projection' && $section['proj'])
                                                            ? (float) ($section['proj']->byScTiers($__scIdTC)[$__tIdTC] ?? 0)
                                                            : $tRealise;
                                                    @endphp
                                                    <td class="text-end" style="padding:3px 8px;color:{{ $tRealise > 0 ? '#666' : '#1565C0' }};font-size:10px;">{{ $tGrand > 0 ? number_format($tGrand, 2, ',', ' ').' €' : '—' }}</td>
                                                @elseif ($parOperations)
                                                    @php
                                                        $__scIdTOp = (int) ($sc['sous_categorie_id'] ?? 0);
                                                        $__tIdTOp = (int) ($t['tiers_id'] ?? 0);
                                                        $projTiersOps = ($mode === 'projection' && $section['proj'])
                                                            ? ($section['proj']->byScTiersOp($__scIdTOp)[$__tIdTOp] ?? [])
                                                            : [];
                                                    @endphp
                                                    @foreach ($operationNames as $opId => $opNom)
                                                        @php
                                                            $tOpVal = ($mode === 'projection' && $section['proj'])
                                                                ? (float) ($projTiersOps[$opId] ?? 0)
                                                                : (float) ($t['operations'][$opId] ?? 0);
                                                        @endphp
                                                        <td class="text-end" style="padding:4px 8px;color:#888;font-size:11px;">
                                                            {{ $tOpVal > 0 ? number_format($tOpVal, 2, ',', ' ').' €' : '—' }}
                                                        </td>
                                                    @endforeach
                                                    {{-- Total tiers opérations --}}
                                                    @if ($mode === 'projection')
                                                        @php
                                                            $scIdT = (int) ($sc['sous_categorie_id'] ?? 0);
                                                            $tIdT = (int) ($t['tiers_id'] ?? 0);
                                                            $tProjGrand = (float) ($section['proj']->byScTiers($scIdT)[$tIdT] ?? 0);
                                                        @endphp
                                                        <td class="text-end" style="padding:4px 8px;color:{{ $tRealise > 0 ? '#666' : '#1565C0' }};font-size:11px;">
                                                            {{ $tProjGrand > 0 ? number_format($tProjGrand, 2, ',', ' ').' €' : '—' }}
                                                        </td>
                                                    @else
                                                        <td class="text-end" style="padding:4px 8px;color:#666;font-size:11px;">{{ $tRealise > 0 ? number_format($tRealise, 2, ',', ' ').' €' : '—' }}</td>
                                                    @endif
                                                @elseif ($parSeances)
                                                    @foreach ($seances as $s)
                                                        @if ($mode === 'projection')
                                                            @php
                                                                $__scIdTs = (int) ($sc['sous_categorie_id'] ?? 0);
                                                                $__tIdTs = (int) ($t['tiers_id'] ?? 0);
                                                                $projVal = (float) ($section['proj']->byScTiersSeance($__scIdTs)[$__tIdTs][$s] ?? 0);
                                                                $rVal = (float) ($t['seances'][$s] ?? 0);
                                                                $projColor = ($rVal <= 0 && $projVal > 0) ? '#1565C0' : '#888';
                                                            @endphp
                                                            <td class="text-end" style="padding:4px 8px;color:{{ $projColor }};font-size:12px;">
                                                                @if ($projVal > 0)
                                                                    {{ number_format($projVal, 2, ',', ' ') }} &euro;
                                                                @else
                                                                    &mdash;
                                                                @endif
                                                            </td>
                                                        @else
                                                            <td class="text-end" style="padding:4px 8px;color:#888;font-size:12px;">
                                                                @if (($t['seances'][$s] ?? 0) > 0)
                                                                    {{ number_format($t['seances'][$s], 2, ',', ' ') }} &euro;
                                                                @else
                                                                    &mdash;
                                                                @endif
                                                            </td>
                                                        @endif
                                                    @endforeach
                                                    {{-- Total tiers séances --}}
                                                    @if ($mode === 'projection')
                                                        @php
                                                            $__scIdTt = (int) ($sc['sous_categorie_id'] ?? 0);
                                                            $__tIdTt = (int) ($t['tiers_id'] ?? 0);
                                                            $projTTotal = (float) ($section['proj']->byScTiers($__scIdTt)[$__tIdTt] ?? 0);
                                                        @endphp
                                                        <td class="text-end" style="padding:4px 8px;color:#666;font-size:12px;">{{ number_format($projTTotal, 2, ',', ' ') }} &euro;</td>
                                                    @else
                                                        <td class="text-end" style="padding:4px 8px;color:#666;font-size:12px;">{{ number_format($t['montant'], 2, ',', ' ') }} &euro;</td>
                                                    @endif
                                                @else
                                                    @if ($mode === 'projection')
                                                        @php
                                                            $__scIdTm = (int) ($sc['sous_categorie_id'] ?? 0);
                                                            $__tIdTm = (int) ($t['tiers_id'] ?? 0);
                                                            $projT = (float) ($section['proj']->byScTiers($__scIdTm)[$__tIdTm] ?? 0);
                                                        @endphp
                                                        <td class="text-end" style="padding:4px 12px;color:#666;font-size:12px;">{{ number_format($projT, 2, ',', ' ') }} &euro;</td>
                                                    @else
                                                        <td class="text-end" style="padding:4px 12px;color:#666;font-size:12px;">{{ number_format($t['montant'], 2, ',', ' ') }} &euro;</td>
                                                    @endif
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
                            $totalSectionOps = [];
                            if ($parOperations) {
                                foreach ($operationNames as $opId => $opNom) {
                                    $totalSectionOps[$opId] = 0.0;
                                    foreach ($section['data'] as $cat) {
                                        $totalSectionOps[$opId] += (float) ($cat['operations'][$opId] ?? 0);
                                    }
                                }
                            }
                            $totalSectionSeanceOps = [];
                            if ($combinedMode) {
                                foreach ($section['data'] as $cat) {
                                    foreach ($cat['seance_operations'] ?? [] as $s => $ops) {
                                        foreach ($ops as $opId => $m) {
                                            $totalSectionSeanceOps[$s][$opId] = ($totalSectionSeanceOps[$s][$opId] ?? 0.0) + (float) $m;
                                        }
                                    }
                                }
                            }
                        @endphp
                        <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="2" style="padding:9px 12px;">TOTAL {{ $section['label'] }}</td>
                            @if ($combinedMode)
                                @foreach ($operationNames as $opId => $opNom)
                                    @foreach ($seancesParOperation[$opId] ?? [] as $s)
                                        @php
                                            $totSO = ($mode === 'projection' && $section['proj'])
                                                ? (float) ($section['proj']->bySeanceOp()[$s][$opId] ?? 0)
                                                : (float) ($totalSectionSeanceOps[$s][$opId] ?? 0);
                                        @endphp
                                        <td class="text-end" style="padding:7px 4px;font-size:10px;">{{ $totSO > 0 ? number_format($totSO, 2, ',', ' ').' €' : '—' }}</td>
                                    @endforeach
                                    @php
                                        $totOp = ($mode === 'projection' && $section['proj'])
                                            ? (float) ($section['proj']->byOp()[$opId] ?? 0)
                                            : (float) ($totalSectionOps[$opId] ?? 0);
                                    @endphp
                                    <td class="text-end" style="padding:7px 4px;font-size:10px;border-left:1px solid rgba(255,255,255,.15);">{{ number_format($totOp, 2, ',', ' ') }} &euro;</td>
                                @endforeach
                                @php
                                    $projGrandTotal = ($mode === 'projection' && $section['proj'])
                                        ? $section['proj']->total()
                                        : $section['totalMontant'];
                                    $projectedSectionTotals[$section['label']] = $projGrandTotal;
                                    if ($mode === 'projection' && $section['proj']) {
                                        $projectedOpSectionTotals[$section['label']] = $section['proj']->byOp();
                                    }
                                @endphp
                                <td class="text-end" style="padding:7px 8px;">{{ number_format($projGrandTotal, 2, ',', ' ') }} &euro;</td>
                            @elseif ($parSeances)
                                @foreach ($seances as $s)
                                    @if ($mode === 'projection')
                                        @php
                                            $projTotSeance = 0.0;
                                            foreach ($section['data'] as $__cat) {
                                                foreach ($__cat['sous_categories'] as $__sc) {
                                                    $projTotSeance += (float) ($section['proj']->byScSeance()[(int) ($__sc['sous_categorie_id'] ?? 0)][$s] ?? 0);
                                                }
                                            }
                                        @endphp
                                        <td class="text-end" style="padding:9px 8px;">{{ number_format($projTotSeance, 2, ',', ' ') }} &euro;</td>
                                    @else
                                        <td class="text-end" style="padding:9px 8px;">{{ number_format($totalSectionSeances[$s], 2, ',', ' ') }} &euro;</td>
                                    @endif
                                @endforeach
                                {{-- Grand total de section (séances) --}}
                                @if ($mode === 'projection')
                                    @php
                                        $projGrandTotal = (float) ($section['proj']->total());
                                        $projectedSectionTotals[$section['label']] = $projGrandTotal;
                                    @endphp
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($projGrandTotal, 2, ',', ' ') }} &euro;</td>
                                @else
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                                @endif
                            @elseif ($parOperations)
                                @foreach ($operationNames as $opId => $opNom)
                                    @if ($mode === 'projection')
                                        @php $projOpTotal = (float) ($section['proj']->byOp()[$opId] ?? 0); @endphp
                                        <td class="text-end" style="padding:9px 8px;font-size:12px;">{{ number_format($projOpTotal, 2, ',', ' ') }} &euro;</td>
                                    @else
                                        <td class="text-end" style="padding:9px 8px;font-size:12px;">{{ number_format($totalSectionOps[$opId], 2, ',', ' ') }} &euro;</td>
                                    @endif
                                @endforeach
                                {{-- Grand total de section (opérations) --}}
                                @if ($mode === 'projection')
                                    @php
                                        $projGrandTotal = array_sum($section['proj']->byOp());
                                        $projectedSectionTotals[$section['label']] = $projGrandTotal;
                                        $projectedOpSectionTotals[$section['label']] = $section['proj']->byOp();
                                    @endphp
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($projGrandTotal, 2, ',', ' ') }} &euro;</td>
                                @else
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                                @endif
                            @else
                                @if ($mode === 'projection')
                                    @php
                                        $projSecTotal = (float) ($section['proj']->total());
                                        $projectedSectionTotals[$section['label']] = $projSecTotal;
                                    @endphp
                                    <td class="text-end" style="padding:9px 12px;">{{ number_format($projSecTotal, 2, ',', ' ') }} &euro;</td>
                                @else
                                    <td class="text-end" style="padding:9px 12px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                                @endif
                            @endif
                        </tr>

                        {{-- Résultat par opération — injecté dans le tableau RECETTES pour alignement colonnes --}}
                        @if ($section['label'] === 'RECETTES' && $parOperations && count($operationNames) > 1)
                            @php
                                $displayResultat = $resultatNet;
                                if ($mode === 'projection' && count($projectedSectionTotals) === 2) {
                                    $displayResultat = ($projectedSectionTotals['RECETTES'] ?? 0) - ($projectedSectionTotals['DÉPENSES'] ?? 0);
                                }
                            @endphp
                            <tr style="background:{{ $displayResultat >= 0 ? '#2E7D32' : '#B5453A' }};color:#fff;font-weight:700;font-size:14px;">
                                <td colspan="2" style="padding:9px 12px;">RÉSULTAT</td>
                                @foreach ($operationNames as $opId => $opNom)
                                    @php
                                        if ($mode === 'projection' && ! empty($projectedOpSectionTotals)) {
                                            $opResultat = (float) ($projectedOpSectionTotals['RECETTES'][$opId] ?? 0) - (float) ($projectedOpSectionTotals['DÉPENSES'][$opId] ?? 0);
                                        } else {
                                            $recOp = 0.0; $depOp = 0.0;
                                            foreach (($produits ?? []) as $_c) { $recOp += (float) ($_c['operations'][$opId] ?? 0); }
                                            foreach (($charges ?? []) as $_c) { $depOp += (float) ($_c['operations'][$opId] ?? 0); }
                                            $opResultat = $recOp - $depOp;
                                        }
                                    @endphp
                                    @if ($combinedMode)
                                        @foreach ($seancesParOperation[$opId] ?? [] as $__s)
                                            <td></td>
                                        @endforeach
                                    @endif
                                    <td class="text-end" style="padding:9px 8px;font-size:{{ $combinedMode ? '10' : '12' }}px;{{ $combinedMode ? 'border-left:1px solid rgba(255,255,255,.15);' : '' }}">
                                        {{ $opResultat >= 0 ? '+' : '' }}{{ number_format($opResultat, 2, ',', ' ') }} &euro;
                                    </td>
                                @endforeach
                                <td class="text-end" style="padding:9px 8px;">
                                    {{ $displayResultat >= 0 ? '+' : '' }}{{ number_format($displayResultat, 2, ',', ' ') }} &euro;
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach

        {{-- Barre résultat net (mode sans opérations en colonnes) --}}
        @if (! $parOperations || count($operationNames) <= 1)
        @php
            $displayResultat = $resultatNet;
            if ($mode === 'projection' && count($projectedSectionTotals) === 2) {
                $displayResultat = ($projectedSectionTotals['RECETTES'] ?? 0) - ($projectedSectionTotals['DÉPENSES'] ?? 0);
            }
        @endphp
        <div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
             style="background:{{ $displayResultat >= 0 ? '#2E7D32' : '#B5453A' }};color:#fff;font-size:1.1rem;font-weight:700;">
            <span>{{ $displayResultat >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
            <span>{{ number_format(abs($displayResultat), 2, ',', ' ') }} &euro;</span>
        </div>
        @endif
    @endif
</div>
