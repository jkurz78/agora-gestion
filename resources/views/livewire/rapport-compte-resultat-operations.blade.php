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

                {{-- Dropdown Mode --}}
                <select wire:model.live="mode" class="form-select form-select-sm" style="width:auto;">
                    <option value="realise">Réalisé</option>
                    <option value="comparaison">Comparaison</option>
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

            // Indexe une hiérarchie de prévisions en sous-dictionnaires plats
            $indexPrevisions = function (array $hierarchy) use ($parOperations): array {
                $idx = [
                    'cat'         => [],  // cat_id => montant
                    'cat_seance'  => [],  // cat_id => [seance => montant]
                    'cat_ops'     => [],  // cat_id => [op_id => montant]
                    'sc'          => [],  // sc_id  => montant
                    'sc_seance'   => [],  // sc_id  => [seance => montant]
                    'sc_ops'      => [],  // sc_id  => [op_id => montant]
                    'tiers'       => [],  // sc_id  => [tiers_id => montant]
                    'tiers_seance'=> [],  // sc_id  => [tiers_id => [seance => montant]]
                ];
                foreach ($hierarchy as $cat) {
                    $cId = $cat['categorie_id'];
                    $idx['cat'][$cId] = (float) ($cat['montant'] ?? $cat['total'] ?? 0);
                    foreach (($cat['seances'] ?? []) as $s => $m) {
                        $idx['cat_seance'][$cId][$s] = (float) $m;
                    }
                    foreach (($cat['operations'] ?? []) as $opId => $m) {
                        $idx['cat_ops'][$cId][$opId] = (float) $m;
                    }
                    foreach ($cat['sous_categories'] as $sc) {
                        $scId = $sc['sous_categorie_id'];
                        $idx['sc'][$scId] = (float) ($sc['montant'] ?? $sc['total'] ?? 0);
                        foreach (($sc['seances'] ?? []) as $s => $m) {
                            $idx['sc_seance'][$scId][$s] = (float) $m;
                        }
                        foreach (($sc['operations'] ?? []) as $opId => $m) {
                            $idx['sc_ops'][$scId][$opId] = (float) $m;
                        }
                        foreach (($sc['tiers'] ?? []) as $t) {
                            $tId = $t['tiers_id'];
                            $idx['tiers'][$scId][$tId] = (float) ($t['montant'] ?? $t['total'] ?? 0);
                            foreach (($t['seances'] ?? []) as $s => $m) {
                                $idx['tiers_seance'][$scId][$tId][$s] = (float) $m;
                            }
                        }
                    }
                }
                return $idx;
            };

            $idxPrevCharges  = $indexPrevisions($previsionsCharges);
            $idxPrevProduits = $indexPrevisions($previsionsProduits);

            // Rend une cellule "stack" Prévu / Réalisé / Écart (mode comparaison)
            $renderCellule = function (float $realise, float $prevu, bool $isDepenses = false): string {
                $r = number_format($realise, 2, ',', ' ');
                $p = number_format($prevu,   2, ',', ' ');
                $ecart = $realise - $prevu;

                // Côté dépenses : écart positif = dépassement = rouge. Côté recettes : écart positif = bonne nouvelle = vert.
                $isPositive = $isDepenses ? ($ecart < 0) : ($ecart > 0);
                $couleurEcart = abs($ecart) < 0.01 ? '#6c757d' : ($isPositive ? '#2E7D32' : '#B5453A');

                $signe = $ecart > 0 ? '+' : '';
                $e = $signe . number_format($ecart, 2, ',', ' ');

                return '<div style="line-height:1.2;font-size:11px">'
                    . '<div style="color:#6c757d">' . $p . '</div>'
                    . '<div style="font-weight:600">' . $r . '</div>'
                    . '<div style="color:' . $couleurEcart . ';font-size:10px">' . $e . '</div>'
                    . '</div>';
            };

            // Fusionne l'arbre réalisé avec l'arbre prévisionnel pour que les
            // catégories / sous-catégories / tiers présents uniquement en
            // prévisions apparaissent aussi dans le rendu (avec réalisé=0).
            $mergeForDisplay = function (array $realise, array $previsions) {
                $byCatId = [];
                foreach ($realise as $cat) {
                    $byCatId[(int) $cat['categorie_id']] = $cat;
                }
                foreach ($previsions as $prevCat) {
                    $catId = (int) $prevCat['categorie_id'];
                    if (! isset($byCatId[$catId])) {
                        $byCatId[$catId] = [
                            'categorie_id' => $catId,
                            'label' => $prevCat['label'],
                            'sous_categories' => [],
                            'seances' => [],
                            'operations' => [],
                            'total' => 0.0,
                            'montant' => 0.0,
                        ];
                    }
                    $byScId = [];
                    foreach ($byCatId[$catId]['sous_categories'] as $sc) {
                        $byScId[(int) $sc['sous_categorie_id']] = $sc;
                    }
                    foreach ($prevCat['sous_categories'] as $prevSc) {
                        $scId = (int) $prevSc['sous_categorie_id'];
                        if (! isset($byScId[$scId])) {
                            $byScId[$scId] = [
                                'sous_categorie_id' => $scId,
                                'label' => $prevSc['label'],
                                'tiers' => [],
                                'seances' => [],
                                'operations' => [],
                                'total' => 0.0,
                                'montant' => 0.0,
                            ];
                        }
                        $byTId = [];
                        foreach (($byScId[$scId]['tiers'] ?? []) as $t) {
                            $byTId[(int) $t['tiers_id']] = $t;
                        }
                        foreach (($prevSc['tiers'] ?? []) as $prevT) {
                            $tId = (int) $prevT['tiers_id'];
                            if (! isset($byTId[$tId])) {
                                $byTId[$tId] = [
                                    'tiers_id' => $tId,
                                    'label' => $prevT['label'],
                                    'type' => $prevT['type'] ?? null,
                                    'seances' => [],
                                    'total' => 0.0,
                                    'montant' => 0.0,
                                ];
                            }
                        }
                        $byScId[$scId]['tiers'] = array_values($byTId);
                    }
                    $byCatId[$catId]['sous_categories'] = array_values($byScId);
                }
                return array_values($byCatId);
            };

            $chargesDisplay  = $mode !== 'realise' ? $mergeForDisplay($charges, $previsionsCharges)   : $charges;
            $produitsDisplay = $mode !== 'realise' ? $mergeForDisplay($produits, $previsionsProduits) : $produits;

            // Projections par séance (calculées côté Builder)
            $projCharges = $projections['charges'] ?? null;
            $projProduits = $projections['produits'] ?? null;

            // Helper projection : retourne réel si > 0, sinon prévu
            $projeter = function (float $realise, float $prevu): float {
                return $realise > 0 ? $realise : $prevu;
            };

            // True si la valeur affichée provient du prévisionnel (réel = 0)
            $isProjectionPrevu = function (float $realise): bool {
                return $realise <= 0;
            };

            // Triplet formaté pour le mode parSeances=OFF + comparaison=ON
            // (utilisé pour rendre 3 <td> distincts au lieu d'une cellule empilée)
            $formatTriad = function (float $realise, float $prevu, bool $isDepenses = false): array {
                $ecart = $realise - $prevu;
                $isPositive = $isDepenses ? ($ecart < 0) : ($ecart > 0);
                $ecartColor = abs($ecart) < 0.01 ? '#6c757d' : ($isPositive ? '#2E7D32' : '#B5453A');
                $signe = $ecart > 0 ? '+' : '';
                return [
                    'prevu' => number_format($prevu, 2, ',', ' '),
                    'realise' => number_format($realise, 2, ',', ' '),
                    'ecart' => $signe . number_format($ecart, 2, ',', ' '),
                    'ecartColor' => $ecartColor,
                ];
            };

            // ---------------------------------------------------------------
            // Calcul du colspan total pour les lignes pleine-largeur
            // ---------------------------------------------------------------
            $nbDataCols = 1; // défaut : 1 colonne "Montant"
            if ($parSeances) {
                $nbDataCols = count($seances) + 1; // séances + Total
            } elseif ($parOperations) {
                $nbOps = count($operationNames);
                if ($mode === 'comparaison') {
                    $nbDataCols = ($nbOps + 1) * 3; // (ops + total) × 3 sous-colonnes
                } else {
                    $nbDataCols = $nbOps + 1; // ops + Total
                }
            } elseif ($mode === 'comparaison') {
                $nbDataCols = 3; // Prévu / Réalisé / Écart
            } elseif ($mode === 'projection') {
                $nbDataCols = 1; // 1 colonne "Projeté"
            }
            $totalColspan = 2 + $nbDataCols;
        @endphp

        @php $projectedSectionTotals = []; @endphp
        @foreach ([
            ['data' => $chargesDisplay, 'prevDisplay' => $previsionsCharges, 'label' => 'DÉPENSES', 'totalMontant' => $totalCharges, 'proj' => $projCharges],
            ['data' => $produitsDisplay, 'prevDisplay' => $previsionsProduits, 'label' => 'RECETTES', 'totalMontant' => $totalProduits, 'proj' => $projProduits],
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
                        @elseif ($parOperations)
                            {{-- En-tête niveau 1 : noms des opérations --}}
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                @foreach ($operationNames as $opId => $opNom)
                                    @if ($mode === 'comparaison')
                                        <td class="text-end" style="font-size:11px;opacity:.85;padding:4px 4px;" colspan="3">
                                            {{ \Illuminate\Support\Str::limit($opNom, 20) }}
                                        </td>
                                    @else
                                        <td class="text-end" style="width:100px;font-size:11px;opacity:.85;padding:4px 8px;" title="{{ $opNom }}">
                                            {{ \Illuminate\Support\Str::limit($opNom, 20) }}
                                        </td>
                                    @endif
                                @endforeach
                                @if ($mode === 'comparaison')
                                    <td class="text-end" style="font-size:11px;opacity:.85;padding:4px 4px;" colspan="3">Total</td>
                                @else
                                    <td class="text-end" style="width:100px;font-size:11px;opacity:.85;padding:4px 8px;">Total</td>
                                @endif
                            </tr>
                            {{-- En-tête niveau 2 : Prévu/Réel/Éc. (mode comparaison uniquement) --}}
                            @if ($mode === 'comparaison')
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                @foreach ($operationNames as $opId => $opNom)
                                    <td class="text-end" style="width:70px;font-size:10px;opacity:.7;padding:2px 4px;">Prévu</td>
                                    <td class="text-end" style="width:70px;font-size:10px;opacity:.7;padding:2px 4px;">Réel</td>
                                    <td class="text-end" style="width:70px;font-size:10px;opacity:.7;padding:2px 4px;">Éc.</td>
                                @endforeach
                                <td class="text-end" style="width:70px;font-size:10px;opacity:.7;padding:2px 4px;">Prévu</td>
                                <td class="text-end" style="width:70px;font-size:10px;opacity:.7;padding:2px 4px;">Réel</td>
                                <td class="text-end" style="width:70px;font-size:10px;opacity:.7;padding:2px 4px;">Éc.</td>
                            </tr>
                            @endif
                        @else
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                @if ($mode === 'comparaison')
                                    <td class="text-end" style="width:110px;font-size:12px;opacity:.85;padding:4px 8px;">Prévu</td>
                                    <td class="text-end" style="width:110px;font-size:12px;opacity:.85;padding:4px 8px;">Réalisé</td>
                                    <td class="text-end" style="width:110px;font-size:12px;opacity:.85;padding:4px 8px;">Écart</td>
                                @elseif ($mode === 'projection')
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
                                $scVisibles = collect($cat['sous_categories'])->filter(function ($sc) use ($parSeances, $parOperations, $mode, $sectionIdx) {
                                    $realise = $parSeances ? ($sc['total'] ?? 0) : ($sc['montant'] ?? 0);
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
                                    @if ($parSeances)
                                        @foreach ($seances as $s)
                                            @if ($mode === 'comparaison')
                                                <td class="text-end" style="padding:7px 8px;">
                                                    {!! $renderCellule(
                                                        (float) ($cat['seances'][$s] ?? 0),
                                                        (float) ($sectionIdx['cat_seance'][$cat['categorie_id']][$s] ?? 0),
                                                        $isDepenses
                                                    ) !!}
                                                </td>
                                            @elseif ($mode === 'projection')
                                                @php
                                                    $rVal = (float) ($cat['seances'][$s] ?? 0);
                                                    $pVal = (float) ($sectionIdx['cat_seance'][$cat['categorie_id']][$s] ?? 0);
                                                    $projVal = $projeter($rVal, $pVal);
                                                    $projColor = $isProjectionPrevu($rVal) && $projVal > 0 ? '#1565C0' : 'inherit';
                                                @endphp
                                                <td class="text-end fw-bold" style="padding:7px 8px;color:{{ $projColor }};">
                                                    @if ($projVal > 0)
                                                        {{ number_format($projVal, 2, ',', ' ') }} &euro;
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
                                        {{-- Colonne Total séances --}}
                                        @if ($mode === 'comparaison')
                                            <td class="text-end" style="padding:7px 8px;">
                                                {!! $renderCellule(
                                                    (float) ($cat['total'] ?? 0),
                                                    (float) ($sectionIdx['cat'][$cat['categorie_id']] ?? 0),
                                                    $isDepenses
                                                ) !!}
                                            </td>
                                        @elseif ($mode === 'projection')
                                            @php
                                                $rTotal = (float) ($cat['total'] ?? 0);
                                                $pTotal = (float) ($sectionIdx['cat'][$cat['categorie_id']] ?? 0);
                                                // Total projeté = somme des projections par séance
                                                $projTotal = 0.0;
                                                foreach ($seances as $_s) {
                                                    $projTotal += $projeter(
                                                        (float) ($cat['seances'][$_s] ?? 0),
                                                        (float) ($sectionIdx['cat_seance'][$cat['categorie_id']][$_s] ?? 0)
                                                    );
                                                }
                                            @endphp
                                            <td class="text-end fw-bold" style="padding:7px 8px;">
                                                {{ number_format($projTotal, 2, ',', ' ') }} &euro;
                                            </td>
                                        @else
                                            <td class="text-end fw-bold" style="padding:7px 8px;">{{ number_format($cat['total'], 2, ',', ' ') }} &euro;</td>
                                        @endif
                                    @elseif ($parOperations)
                                        @foreach ($operationNames as $opId => $opNom)
                                            @php
                                                $opRealise = (float) ($cat['operations'][$opId] ?? 0);
                                                $opPrevu   = (float) ($sectionIdx['cat_ops'][$cat['categorie_id']][$opId] ?? 0);
                                            @endphp
                                            @if ($mode === 'comparaison')
                                                @php $tri = $formatTriad($opRealise, $opPrevu, $isDepenses); @endphp
                                                <td class="text-end" style="padding:7px 4px;color:#6c757d;font-size:12px;">{{ $tri['prevu'] }} &euro;</td>
                                                <td class="text-end fw-bold" style="padding:7px 4px;font-size:12px;">{{ $tri['realise'] }} &euro;</td>
                                                <td class="text-end" style="padding:7px 4px;color:{{ $tri['ecartColor'] }};font-size:12px;">{{ $tri['ecart'] }} &euro;</td>
                                            @elseif ($mode === 'projection')
                                                @php $projVal = $projeter($opRealise, $opPrevu); @endphp
                                                <td class="text-end fw-bold" style="padding:7px 8px;font-size:12px;">
                                                    {{ $projVal > 0 ? number_format($projVal, 2, ',', ' ').' €' : '—' }}
                                                </td>
                                            @else
                                                <td class="text-end fw-bold" style="padding:7px 8px;font-size:12px;">
                                                    {{ $opRealise > 0 ? number_format($opRealise, 2, ',', ' ').' €' : '—' }}
                                                </td>
                                            @endif
                                        @endforeach
                                        {{-- Colonne Total opérations --}}
                                        @php
                                            $catTotalRealise = (float) ($cat['montant'] ?? 0);
                                            $catTotalPrevu   = (float) ($sectionIdx['cat'][$cat['categorie_id']] ?? 0);
                                        @endphp
                                        @if ($mode === 'comparaison')
                                            @php $tri = $formatTriad($catTotalRealise, $catTotalPrevu, $isDepenses); @endphp
                                            <td class="text-end" style="padding:7px 4px;color:#6c757d;font-weight:600;">{{ $tri['prevu'] }} &euro;</td>
                                            <td class="text-end fw-bold" style="padding:7px 4px;">{{ $tri['realise'] }} &euro;</td>
                                            <td class="text-end" style="padding:7px 4px;color:{{ $tri['ecartColor'] }};">{{ $tri['ecart'] }} &euro;</td>
                                        @elseif ($mode === 'projection')
                                            @php
                                                $projCatTotal = (float) ($section['proj']['cat'][$cat['categorie_id']] ?? 0);
                                            @endphp
                                            <td class="text-end fw-bold" style="padding:7px 8px;">{{ number_format($projCatTotal, 2, ',', ' ') }} &euro;</td>
                                        @else
                                            <td class="text-end fw-bold" style="padding:7px 8px;">{{ number_format($catTotalRealise, 2, ',', ' ') }} &euro;</td>
                                        @endif
                                    @else
                                        @if ($mode === 'comparaison')
                                            @php $tri = $formatTriad((float) ($cat['montant'] ?? 0), (float) ($sectionIdx['cat'][$cat['categorie_id']] ?? 0), $isDepenses); @endphp
                                            <td class="text-end" style="padding:7px 12px;color:#6c757d;">{{ $tri['prevu'] }} &euro;</td>
                                            <td class="text-end fw-bold" style="padding:7px 12px;">{{ $tri['realise'] }} &euro;</td>
                                            <td class="text-end" style="padding:7px 12px;color:{{ $tri['ecartColor'] }};">{{ $tri['ecart'] }} &euro;</td>
                                        @elseif ($mode === 'projection')
                                            @php $projCat = (float) ($section['proj']['cat'][$cat['categorie_id']] ?? 0); @endphp
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
                                        @if ($parSeances)
                                            @foreach ($seances as $s)
                                                @if ($mode === 'comparaison')
                                                    <td class="text-end" style="padding:5px 8px;color:#444;">
                                                        {!! $renderCellule(
                                                            (float) ($sc['seances'][$s] ?? 0),
                                                            (float) ($sectionIdx['sc_seance'][$sc['sous_categorie_id']][$s] ?? 0),
                                                            $isDepenses
                                                        ) !!}
                                                    </td>
                                                @elseif ($mode === 'projection')
                                                    @php
                                                        $rVal = (float) ($sc['seances'][$s] ?? 0);
                                                        $pVal = (float) ($sectionIdx['sc_seance'][$sc['sous_categorie_id']][$s] ?? 0);
                                                        $projVal = $projeter($rVal, $pVal);
                                                        $projColor = $isProjectionPrevu($rVal) && $projVal > 0 ? '#1565C0' : 'inherit';
                                                    @endphp
                                                    <td class="text-end" style="padding:5px 8px;color:{{ $projColor }};">
                                                        @if ($projVal > 0)
                                                            {{ number_format($projVal, 2, ',', ' ') }} &euro;
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
                                            {{-- Colonne Total sc séances --}}
                                            @if ($mode === 'comparaison')
                                                <td class="text-end" style="padding:5px 8px;">
                                                    {!! $renderCellule(
                                                        (float) ($sc['total'] ?? 0),
                                                        (float) ($sectionIdx['sc'][$sc['sous_categorie_id']] ?? 0),
                                                        $isDepenses
                                                    ) !!}
                                                </td>
                                            @elseif ($mode === 'projection')
                                                @php
                                                    $projScTotal = 0.0;
                                                    foreach ($seances as $_s) {
                                                        $projScTotal += $projeter(
                                                            (float) ($sc['seances'][$_s] ?? 0),
                                                            (float) ($sectionIdx['sc_seance'][$sc['sous_categorie_id']][$_s] ?? 0)
                                                        );
                                                    }
                                                @endphp
                                                <td class="text-end fw-bold" style="padding:5px 8px;">{{ number_format($projScTotal, 2, ',', ' ') }} &euro;</td>
                                            @else
                                                <td class="text-end fw-bold" style="padding:5px 8px;">{{ number_format($sc['total'], 2, ',', ' ') }} &euro;</td>
                                            @endif
                                        @elseif ($parOperations)
                                            @foreach ($operationNames as $opId => $opNom)
                                                @php
                                                    $opRealise = (float) ($sc['operations'][$opId] ?? 0);
                                                    $opPrevu   = (float) ($sectionIdx['sc_ops'][$sc['sous_categorie_id']][$opId] ?? 0);
                                                @endphp
                                                @if ($mode === 'comparaison')
                                                    @php $tri = $formatTriad($opRealise, $opPrevu, $isDepenses); @endphp
                                                    <td class="text-end" style="padding:5px 4px;color:#6c757d;font-size:12px;">{{ $tri['prevu'] }} &euro;</td>
                                                    <td class="text-end" style="padding:5px 4px;font-size:12px;color:#444;">{{ $tri['realise'] }} &euro;</td>
                                                    <td class="text-end" style="padding:5px 4px;color:{{ $tri['ecartColor'] }};font-size:12px;">{{ $tri['ecart'] }} &euro;</td>
                                                @elseif ($mode === 'projection')
                                                    @php $projVal = $projeter($opRealise, $opPrevu); @endphp
                                                    <td class="text-end" style="padding:5px 8px;font-size:12px;color:#444;">
                                                        {{ $projVal > 0 ? number_format($projVal, 2, ',', ' ').' €' : '—' }}
                                                    </td>
                                                @else
                                                    <td class="text-end" style="padding:5px 8px;font-size:12px;color:#444;">
                                                        {{ $opRealise > 0 ? number_format($opRealise, 2, ',', ' ').' €' : '—' }}
                                                    </td>
                                                @endif
                                            @endforeach
                                            {{-- Colonne Total sc opérations --}}
                                            @php
                                                $scTotalRealise = (float) ($sc['montant'] ?? 0);
                                                $scTotalPrevu   = (float) ($sectionIdx['sc'][$sc['sous_categorie_id']] ?? 0);
                                            @endphp
                                            @if ($mode === 'comparaison')
                                                @php $tri = $formatTriad($scTotalRealise, $scTotalPrevu, $isDepenses); @endphp
                                                <td class="text-end" style="padding:5px 4px;color:#6c757d;">{{ $tri['prevu'] }} &euro;</td>
                                                <td class="text-end fw-bold" style="padding:5px 4px;color:#444;">{{ $tri['realise'] }} &euro;</td>
                                                <td class="text-end" style="padding:5px 4px;color:{{ $tri['ecartColor'] }};">{{ $tri['ecart'] }} &euro;</td>
                                            @elseif ($mode === 'projection')
                                                @php $projScOpsTotal = (float) ($section['proj']['sc'][$sc['sous_categorie_id']] ?? 0); @endphp
                                                <td class="text-end fw-bold" style="padding:5px 8px;color:#444;">{{ number_format($projScOpsTotal, 2, ',', ' ') }} &euro;</td>
                                            @else
                                                <td class="text-end fw-bold" style="padding:5px 8px;color:#444;">{{ number_format($scTotalRealise, 2, ',', ' ') }} &euro;</td>
                                            @endif
                                        @else
                                            @if ($mode === 'comparaison')
                                                @php $tri = $formatTriad((float) ($sc['montant'] ?? 0), (float) ($sectionIdx['sc'][$sc['sous_categorie_id']] ?? 0), $isDepenses); @endphp
                                                <td class="text-end" style="padding:5px 12px;color:#6c757d;">{{ $tri['prevu'] }} &euro;</td>
                                                <td class="text-end fw-bold" style="padding:5px 12px;color:#444;">{{ $tri['realise'] }} &euro;</td>
                                                <td class="text-end" style="padding:5px 12px;color:{{ $tri['ecartColor'] }};">{{ $tri['ecart'] }} &euro;</td>
                                            @elseif ($mode === 'projection')
                                                @php $projSc = (float) ($section['proj']['sc'][$sc['sous_categorie_id']] ?? 0); @endphp
                                                <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($projSc, 2, ',', ' ') }} &euro;</td>
                                            @else
                                                <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['montant'], 2, ',', ' ') }} &euro;</td>
                                            @endif
                                        @endif
                                    </tr>

                                    {{-- Lignes tiers (si activé, compatible séances uniquement — pas parOperations) --}}
                                    @if ($parTiers && ! $parOperations && ! empty($sc['tiers']))
                                        @foreach ($sc['tiers'] as $t)
                                            @php
                                                $tRealise = $parSeances ? ($t['total'] ?? 0) : ($t['montant'] ?? 0);
                                                $tPrev = (float) ($sectionIdx['tiers'][$sc['sous_categorie_id']][$t['tiers_id']] ?? 0);
                                                $tVisible = $tRealise > 0 || ($mode !== 'realise' && $tPrev > 0);
                                            @endphp
                                            @if ($tVisible)
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
                                                        @if ($mode === 'comparaison')
                                                            <td class="text-end" style="padding:4px 8px;font-size:12px;">
                                                                {!! $renderCellule(
                                                                    (float) ($t['seances'][$s] ?? 0),
                                                                    (float) ($sectionIdx['tiers_seance'][$sc['sous_categorie_id']][$t['tiers_id']][$s] ?? 0),
                                                                    $isDepenses
                                                                ) !!}
                                                            </td>
                                                        @elseif ($mode === 'projection')
                                                            @php
                                                                $rVal = (float) ($t['seances'][$s] ?? 0);
                                                                $pVal = (float) ($sectionIdx['tiers_seance'][$sc['sous_categorie_id']][$t['tiers_id']][$s] ?? 0);
                                                                $projVal = $projeter($rVal, $pVal);
                                                                $projColor = $isProjectionPrevu($rVal) && $projVal > 0 ? '#1565C0' : '#888';
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
                                                    {{-- Colonne Total tiers séances --}}
                                                    @if ($mode === 'comparaison')
                                                        <td class="text-end" style="padding:4px 8px;font-size:12px;">
                                                            {!! $renderCellule(
                                                                (float) ($t['total'] ?? 0),
                                                                (float) ($sectionIdx['tiers'][$sc['sous_categorie_id']][$t['tiers_id']] ?? 0),
                                                                $isDepenses
                                                            ) !!}
                                                        </td>
                                                    @elseif ($mode === 'projection')
                                                        @php
                                                            $projTTotal = 0.0;
                                                            foreach ($seances as $_s) {
                                                                $projTTotal += $projeter(
                                                                    (float) ($t['seances'][$_s] ?? 0),
                                                                    (float) ($sectionIdx['tiers_seance'][$sc['sous_categorie_id']][$t['tiers_id']][$_s] ?? 0)
                                                                );
                                                            }
                                                        @endphp
                                                        <td class="text-end" style="padding:4px 8px;color:#666;font-size:12px;">{{ number_format($projTTotal, 2, ',', ' ') }} &euro;</td>
                                                    @else
                                                        <td class="text-end" style="padding:4px 8px;color:#666;font-size:12px;">{{ number_format($t['total'], 2, ',', ' ') }} &euro;</td>
                                                    @endif
                                                @else
                                                    @if ($mode === 'comparaison')
                                                        @php $tri = $formatTriad((float) ($t['montant'] ?? 0), (float) ($sectionIdx['tiers'][$sc['sous_categorie_id']][$t['tiers_id']] ?? 0), $isDepenses); @endphp
                                                        <td class="text-end" style="padding:4px 12px;color:#6c757d;font-size:12px;">{{ $tri['prevu'] }} &euro;</td>
                                                        <td class="text-end" style="padding:4px 12px;color:#666;font-size:12px;font-weight:600;">{{ $tri['realise'] }} &euro;</td>
                                                        <td class="text-end" style="padding:4px 12px;color:{{ $tri['ecartColor'] }};font-size:12px;">{{ $tri['ecart'] }} &euro;</td>
                                                    @elseif ($mode === 'projection')
                                                        @php
                                                            $rT = (float) ($t['montant'] ?? 0);
                                                            $pT = (float) ($sectionIdx['tiers'][$sc['sous_categorie_id']][$t['tiers_id']] ?? 0);
                                                            $projT = $projeter($rT, $pT);
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
                            // Sommes prévues par séance pour le total section
                            $totalPrevuSeances = [];
                            if ($mode !== 'realise' && $parSeances) {
                                $totalPrevuSeances = array_fill_keys($seances, 0.0);
                                foreach ($sectionIdx['cat_seance'] as $catSeances) {
                                    foreach ($seances as $s) {
                                        $totalPrevuSeances[$s] += $catSeances[$s] ?? 0.0;
                                    }
                                }
                            }
                            $totalPrevuSection = $mode !== 'realise' ? array_sum($sectionIdx['cat']) : 0.0;

                            // Totaux par opération pour le total section
                            $totalSectionOps = [];
                            $totalPrevuSectionOps = [];
                            if ($parOperations) {
                                foreach ($operationNames as $opId => $opNom) {
                                    $totalSectionOps[$opId] = 0.0;
                                    $totalPrevuSectionOps[$opId] = 0.0;
                                    foreach ($section['data'] as $cat) {
                                        $totalSectionOps[$opId] += (float) ($cat['operations'][$opId] ?? 0);
                                        $totalPrevuSectionOps[$opId] += (float) ($sectionIdx['cat_ops'][$cat['categorie_id']][$opId] ?? 0);
                                    }
                                }
                            }
                        @endphp
                        <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="2" style="padding:9px 12px;">TOTAL {{ $section['label'] }}</td>
                            @if ($parSeances)
                                @foreach ($seances as $s)
                                    @if ($mode === 'comparaison')
                                        <td class="text-end" style="padding:9px 8px;">
                                            {!! $renderCellule(
                                                (float) ($totalSectionSeances[$s] ?? 0),
                                                (float) ($totalPrevuSeances[$s] ?? 0),
                                                $isDepenses
                                            ) !!}
                                        </td>
                                    @elseif ($mode === 'projection')
                                        @php
                                            $projTotSeance = $projeter(
                                                (float) ($totalSectionSeances[$s] ?? 0),
                                                (float) ($totalPrevuSeances[$s] ?? 0)
                                            );
                                        @endphp
                                        <td class="text-end" style="padding:9px 8px;">{{ number_format($projTotSeance, 2, ',', ' ') }} &euro;</td>
                                    @else
                                        <td class="text-end" style="padding:9px 8px;">{{ number_format($totalSectionSeances[$s], 2, ',', ' ') }} &euro;</td>
                                    @endif
                                @endforeach
                                {{-- Grand total de section (séances) --}}
                                @if ($mode === 'comparaison')
                                    <td class="text-end" style="padding:9px 8px;">
                                        {!! $renderCellule(
                                            (float) $section['totalMontant'],
                                            $totalPrevuSection,
                                            $isDepenses
                                        ) !!}
                                    </td>
                                @elseif ($mode === 'projection')
                                    @php
                                        $projGrandTotal = (float) ($section['proj']['total'] ?? 0);
                                        $projectedSectionTotals[$section['label']] = $projGrandTotal;
                                    @endphp
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($projGrandTotal, 2, ',', ' ') }} &euro;</td>
                                @else
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                                @endif
                            @elseif ($parOperations)
                                @foreach ($operationNames as $opId => $opNom)
                                    @if ($mode === 'comparaison')
                                        @php $tri = $formatTriad($totalSectionOps[$opId], $totalPrevuSectionOps[$opId], $isDepenses); @endphp
                                        <td class="text-end" style="padding:9px 4px;opacity:.85;font-size:12px;">{{ $tri['prevu'] }} &euro;</td>
                                        <td class="text-end" style="padding:9px 4px;font-size:12px;">{{ $tri['realise'] }} &euro;</td>
                                        <td class="text-end" style="padding:9px 4px;color:{{ $tri['ecartColor'] }};font-size:12px;">{{ $tri['ecart'] }} &euro;</td>
                                    @elseif ($mode === 'projection')
                                        @php $projOpTotal = $projeter($totalSectionOps[$opId], $totalPrevuSectionOps[$opId]); @endphp
                                        <td class="text-end" style="padding:9px 8px;font-size:12px;">{{ number_format($projOpTotal, 2, ',', ' ') }} &euro;</td>
                                    @else
                                        <td class="text-end" style="padding:9px 8px;font-size:12px;">{{ number_format($totalSectionOps[$opId], 2, ',', ' ') }} &euro;</td>
                                    @endif
                                @endforeach
                                {{-- Grand total de section (opérations) --}}
                                @if ($mode === 'comparaison')
                                    @php $tri = $formatTriad((float) $section['totalMontant'], $totalPrevuSection, $isDepenses); @endphp
                                    <td class="text-end" style="padding:9px 4px;opacity:.85;">{{ $tri['prevu'] }} &euro;</td>
                                    <td class="text-end" style="padding:9px 4px;">{{ $tri['realise'] }} &euro;</td>
                                    <td class="text-end" style="padding:9px 4px;color:{{ $tri['ecartColor'] }};">{{ $tri['ecart'] }} &euro;</td>
                                @elseif ($mode === 'projection')
                                    @php
                                        $projSectionTotal = (float) ($section['proj']['total'] ?? 0);
                                        $projectedSectionTotals[$section['label']] = $projSectionTotal;
                                    @endphp
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($projSectionTotal, 2, ',', ' ') }} &euro;</td>
                                @else
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                                @endif
                            @else
                                @if ($mode === 'comparaison')
                                    @php $tri = $formatTriad((float) $section['totalMontant'], (float) $totalPrevuSection, $isDepenses); @endphp
                                    <td class="text-end" style="padding:9px 12px;opacity:.85;">{{ $tri['prevu'] }} &euro;</td>
                                    <td class="text-end" style="padding:9px 12px;">{{ $tri['realise'] }} &euro;</td>
                                    <td class="text-end" style="padding:9px 12px;color:{{ $tri['ecartColor'] }};">{{ $tri['ecart'] }} &euro;</td>
                                @elseif ($mode === 'projection')
                                    @php
                                        $projSecTotal = (float) ($section['proj']['total'] ?? 0);
                                        $projectedSectionTotals[$section['label']] = $projSecTotal;
                                    @endphp
                                    <td class="text-end" style="padding:9px 12px;">{{ number_format($projSecTotal, 2, ',', ' ') }} &euro;</td>
                                @else
                                    <td class="text-end" style="padding:9px 12px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                                @endif
                            @endif
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach

        {{-- Barre résultat net --}}
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
</div>
