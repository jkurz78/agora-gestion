@extends('pdf.rapport-layout')

@section('styles')
    .cr-tiers td { background: #fff; color: #666; border-bottom: 1px solid #f0f0f0; }
@endsection

@section('content')
    @php
        $previsionnel = $previsionnel ?? false;
        $mode = $mode ?? ($previsionnel ? 'projection' : 'realise');
        $parOperations = $parOperations ?? false;
        $operationNames = $operationNames ?? [];
        $seancesParOperation = $seancesParOperation ?? [];
        $combinedMode = $parSeances && $parOperations;

        // Adaptive sizing
        if ($combinedMode) {
            $nCols = 0;
            foreach ($seancesParOperation as $opSeances) { $nCols += count($opSeances) + 1; }
            $nCols += 1;
            if ($nCols > 20) {
                $colWidth = '30px'; $fontSize = '6px'; $fontSizeSub = '5px'; $fontSizeHeader = '5px'; $pad = '1px 2px';
            } elseif ($nCols > 10) {
                $colWidth = '40px'; $fontSize = '7px'; $fontSizeSub = '6px'; $fontSizeHeader = '6px'; $pad = '2px 3px';
            } else {
                $colWidth = '52px'; $fontSize = '8px'; $fontSizeSub = '7px'; $fontSizeHeader = '7px'; $pad = '3px 4px';
            }
        } elseif ($parOperations) {
            $nCols = count($operationNames);
            if ($nCols > 10) {
                $colWidth = '40px'; $fontSize = '7px'; $fontSizeSub = '6px'; $fontSizeHeader = '6px'; $pad = '2px 3px';
            } elseif ($nCols > 5) {
                $colWidth = '52px'; $fontSize = '8px'; $fontSizeSub = '7px'; $fontSizeHeader = '7px'; $pad = '3px 4px';
            } else {
                $colWidth = '70px'; $fontSize = '10px'; $fontSizeSub = '9px'; $fontSizeHeader = '8px'; $pad = '4px 6px';
            }
        } else {
            $nSeances = $parSeances ? count($seances) : 0;
            if ($nSeances > 10) {
                $colWidth = '40px'; $fontSize = '7px'; $fontSizeSub = '6px'; $fontSizeHeader = '6px'; $pad = '2px 3px';
            } elseif ($nSeances > 5) {
                $colWidth = '52px'; $fontSize = '8px'; $fontSizeSub = '7px'; $fontSizeHeader = '7px'; $pad = '3px 4px';
            } else {
                $colWidth = '70px'; $fontSize = '10px'; $fontSizeSub = '9px'; $fontSizeHeader = '8px'; $pad = '4px 6px';
            }
        }

        $fmt = fn(float $v): string => number_format($v, 2, ',', ' ') . ' €';

        if ($combinedMode) {
            $colCount = 2;
            foreach ($seancesParOperation as $opSeances) { $colCount += count($opSeances) + 1; }
            $colCount += 1;
        } elseif ($parOperations) {
            $colCount = 2 + count($operationNames) + 1;
        } else {
            $colCount = $parSeances ? count($seances) + 3 : 3;
            if ($parTiers) $colCount++;
        }

        $projectedSectionTotals = [];

        $buildPrevIdx = function (array $hierarchy): array {
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
        $prevIdxCharges = $buildPrevIdx($previsionsCharges ?? []);
        $prevIdxProduits = $buildPrevIdx($previsionsProduits ?? []);
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'totalMontant' => $totalCharges, 'proj' => $projCharges ?? null],
               ['data' => $produits, 'label' => 'RECETTES', 'totalMontant' => $totalProduits, 'proj' => $projProduits ?? null]] as $section)
    @php $projMatrix = $section['proj']; @endphp
    <table class="data-table" style="margin-bottom:14px;font-size:{{ $fontSize }};">
        <tbody>
            {{-- Column header --}}
            @if ($combinedMode)
            <tr class="cr-section-header">
                <td colspan="2"></td>
                @foreach ($operationNames as $opId => $opNom)
                    @php $opColspan = count($seancesParOperation[$opId] ?? []) + 1; @endphp
                    <td colspan="{{ $opColspan }}" class="text-center" style="font-size:{{ $fontSizeHeader }};opacity:.85;padding:{{ $pad }};">{{ \Illuminate\Support\Str::limit($opNom, 15) }}</td>
                @endforeach
                <td rowspan="2" class="text-right" style="width:{{ $colWidth }};font-size:{{ $fontSizeHeader }};opacity:.85;padding:{{ $pad }};">Total</td>
            </tr>
            <tr class="cr-section-header">
                <td colspan="2"></td>
                @foreach ($operationNames as $opId => $opNom)
                    @foreach ($seancesParOperation[$opId] ?? [] as $s)
                        <td class="text-right" style="width:{{ $colWidth }};font-size:{{ $fontSizeHeader }};opacity:.75;padding:{{ $pad }};">{{ $s === 0 ? 'H.S.' : 'S'.$s }}</td>
                    @endforeach
                    <td class="text-right" style="width:{{ $colWidth }};font-size:{{ $fontSizeHeader }};opacity:.85;padding:{{ $pad }};font-weight:600;">Tot.</td>
                @endforeach
            </tr>
            @else
            <tr class="cr-section-header">
                <td colspan="2"></td>
                @if ($parOperations)
                    @foreach ($operationNames as $opId => $opNom)
                        <td class="text-right" style="width:{{ $colWidth }};font-size:{{ $fontSizeHeader }};opacity:.85;padding:{{ $pad }};">{{ \Illuminate\Support\Str::limit($opNom, 20) }}</td>
                    @endforeach
                    <td class="text-right" style="width:{{ $colWidth }};font-size:{{ $fontSizeHeader }};opacity:.85;padding:{{ $pad }};">Total</td>
                @elseif ($parSeances)
                    @foreach ($seances as $s)
                        <td class="text-right" style="width:{{ $colWidth }};font-size:{{ $fontSizeHeader }};opacity:.85;padding:{{ $pad }};">{{ $s === 0 ? 'Hors S.' : 'S'.$s }}</td>
                    @endforeach
                    <td class="text-right" style="width:{{ $colWidth }};font-size:{{ $fontSizeHeader }};opacity:.85;padding:{{ $pad }};">Total</td>
                @else
                    <td class="text-right" style="width:100px;font-size:10px;opacity:.85;">{{ $mode === 'projection' ? 'Projeté' : 'Montant' }}</td>
                @endif
            </tr>
            @endif
            <tr class="cr-section-header">
                <td colspan="{{ $colCount }}">{{ $section['label'] }}</td>
            </tr>

            @php $sectionPrevIdx = $section['label'] === 'DÉPENSES' ? $prevIdxCharges : $prevIdxProduits; @endphp
            @foreach ($section['data'] as $cat)
                @php
                    $scVisibles = collect($cat['sous_categories'])->filter(function ($sc) use ($mode, $sectionPrevIdx) {
                        $realise = (float) ($sc['montant'] ?? 0);
                        if ($realise > 0 || collect($sc['operations'] ?? [])->sum() > 0) {
                            return true;
                        }
                        if ($mode === 'realise') {
                            return false;
                        }
                        return ((float) ($sectionPrevIdx['sc'][$sc['sous_categorie_id'] ?? 0] ?? 0)) > 0;
                    });
                @endphp
                @if (! $scVisibles->isEmpty())
                    {{-- Category row --}}
                    <tr class="cr-cat">
                        <td colspan="2">{{ $cat['label'] }}</td>
                        @if ($combinedMode)
                            @php $catId = (int) ($cat['categorie_id'] ?? 0); @endphp
                            @foreach ($operationNames as $opId => $opNom)
                                @foreach ($seancesParOperation[$opId] ?? [] as $s)
                                    @php
                                        $val = ($mode === 'projection' && $projMatrix)
                                            ? collect($cat['sous_categories'])->sum(fn ($__sc) => (float) ($projMatrix->byScSeanceOp()[(int) ($__sc['sous_categorie_id'] ?? 0)][$s][$opId] ?? 0))
                                            : (float) ($cat['seance_operations'][$s][$opId] ?? 0);
                                    @endphp
                                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;font-size:{{ $fontSizeSub }};">{{ $val > 0 ? $fmt($val) : '—' }}</td>
                                @endforeach
                                @php
                                    $opTot = ($mode === 'projection' && $projMatrix)
                                        ? (float) ($projMatrix->byCatOp()[$catId][$opId] ?? 0)
                                        : (float) ($cat['operations'][$opId] ?? 0);
                                @endphp
                                <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;font-size:{{ $fontSizeSub }};">{{ $opTot > 0 ? $fmt($opTot) : '—' }}</td>
                            @endforeach
                            @php
                                $grandTot = ($mode === 'projection' && $projMatrix)
                                    ? (float) ($projMatrix->byCat()[$catId] ?? 0)
                                    : (float) ($cat['montant'] ?? 0);
                            @endphp
                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($grandTot) }}</td>
                        @elseif ($parOperations)
                            @php $catId = (int) ($cat['categorie_id'] ?? 0); @endphp
                            @foreach ($operationNames as $opId => $opNom)
                                @if ($mode === 'projection' && $projMatrix)
                                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt((float) ($projMatrix->byCatOp()[$catId][$opId] ?? 0)) }}</td>
                                @else
                                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ ((float) ($cat['operations'][$opId] ?? 0)) > 0 ? $fmt($cat['operations'][$opId]) : '—' }}</td>
                                @endif
                            @endforeach
                            @if ($mode === 'projection' && $projMatrix)
                                <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt((float) ($projMatrix->byCat()[$catId] ?? 0)) }}</td>
                            @else
                                <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($cat['montant']) }}</td>
                            @endif
                        @elseif ($parSeances)
                            @php $catId = (int) ($cat['categorie_id'] ?? 0); @endphp
                            @foreach ($seances as $s)
                                @php $catSeanceReal = (float) ($cat['seances'][$s] ?? 0); @endphp
                                <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">
                                    @if ($mode === 'projection' && $projMatrix)
                                        @php
                                            $catSeanceProj = 0.0;
                                            foreach ($cat['sous_categories'] as $_sc) {
                                                $_scId = (int) ($_sc['sous_categorie_id'] ?? $_sc['id'] ?? 0);
                                                $catSeanceProj += (float) ($projMatrix->byScSeance()[$_scId][$s] ?? 0);
                                            }
                                        @endphp
                                        <span style="color:{{ $catSeanceProj > 0 && $catSeanceReal == 0 ? '#1565C0' : 'inherit' }}">{{ $catSeanceProj > 0 ? $fmt($catSeanceProj) : '—' }}</span>
                                    @else
                                        {{ $catSeanceReal > 0 ? $fmt($catSeanceReal) : '—' }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">
                                @if ($mode === 'projection' && $projMatrix)
                                    {{ $fmt((float) ($projMatrix->byCat()[$catId] ?? 0)) }}
                                @else
                                    {{ $fmt($cat['montant']) }}
                                @endif
                            </td>
                        @else
                            <td class="text-right">
                                @if ($mode === 'projection' && $projMatrix)
                                    @php $catId = (int) ($cat['categorie_id'] ?? 0); @endphp
                                    {{ $fmt((float) ($projMatrix->byCat()[$catId] ?? $cat['montant'])) }}
                                @else
                                    {{ $fmt($cat['montant']) }}
                                @endif
                            </td>
                        @endif
                    </tr>

                    @foreach ($scVisibles as $sc)
                        @php
                            $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                        @endphp
                        @if ($combinedMode)
                        {{-- Combined mode: column-based SC row --}}
                        <tr class="cr-sub">
                            <td style="width:16px;"></td>
                            <td>{{ $sc['label'] }}</td>
                            @foreach ($operationNames as $opId => $opNom)
                                @foreach ($seancesParOperation[$opId] ?? [] as $s)
                                    @php
                                        $cellVal = ($mode === 'projection' && $projMatrix)
                                            ? (float) ($projMatrix->byScSeanceOp()[$scId][$s][$opId] ?? 0)
                                            : (float) ($sc['seance_operations'][$s][$opId] ?? 0);
                                    @endphp
                                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;font-size:{{ $fontSizeSub }};">{{ $cellVal > 0 ? $fmt($cellVal) : '—' }}</td>
                                @endforeach
                                @php
                                    $scOpVal = ($mode === 'projection' && $projMatrix)
                                        ? (float) ($projMatrix->byScOp()[$scId][$opId] ?? 0)
                                        : (float) ($sc['operations'][$opId] ?? 0);
                                @endphp
                                <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;font-size:{{ $fontSizeSub }};">{{ $scOpVal > 0 ? $fmt($scOpVal) : '—' }}</td>
                            @endforeach
                            @php
                                $scTotVal = ($mode === 'projection' && $projMatrix)
                                    ? (float) ($projMatrix->bySc()[$scId] ?? 0)
                                    : (float) ($sc['montant'] ?? 0);
                            @endphp
                            <td class="text-right fw-bold" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($scTotVal) }}</td>
                        </tr>
                        @else
                        {{-- Sub-category row --}}
                        <tr class="cr-sub">
                            <td style="width:16px;"></td>
                            <td>{{ $sc['label'] }}</td>
                            @if ($parOperations)
                                @foreach ($operationNames as $opId => $opNom)
                                    @if ($mode === 'projection' && $projMatrix)
                                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt((float) ($projMatrix->byScOp()[$scId][$opId] ?? 0)) }}</td>
                                    @else
                                        @php $scOpVal = (float) ($sc['operations'][$opId] ?? 0); @endphp
                                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $scOpVal > 0 ? $fmt($scOpVal) : '—' }}</td>
                                    @endif
                                @endforeach
                                @if ($mode === 'projection' && $projMatrix)
                                    <td class="text-right fw-bold" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt((float) ($projMatrix->bySc()[$scId] ?? 0)) }}</td>
                                @else
                                    <td class="text-right fw-bold" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt((float) ($sc['montant'] ?? 0)) }}</td>
                                @endif
                            @elseif ($parSeances)
                                @foreach ($seances as $s)
                                    @php $scReal = (float) ($sc['seances'][$s] ?? 0); @endphp
                                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">
                                        @if ($mode === 'projection' && $projMatrix)
                                            @php $projVal = (float) ($projMatrix->byScSeance()[$scId][$s] ?? 0); @endphp
                                            <span style="color:{{ $scReal > 0 ? 'inherit' : '#1565C0' }}">{{ $projVal > 0 ? $fmt($projVal) : '—' }}</span>
                                        @else
                                            {{ $scReal > 0 ? $fmt($scReal) : '—' }}
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-right fw-bold" style="padding:{{ $pad }};white-space:nowrap;">
                                    @if ($mode === 'projection' && $projMatrix)
                                        {{ $fmt((float) ($projMatrix->bySc()[$scId] ?? 0)) }}
                                    @else
                                        {{ $fmt((float) ($sc['montant'] ?? 0)) }}
                                    @endif
                                </td>
                            @else
                                @php $scMontant = (float) ($sc['montant'] ?? 0); @endphp
                                <td class="text-right">
                                    @if ($mode === 'projection' && $projMatrix)
                                        {{ $fmt((float) ($projMatrix->bySc()[$scId] ?? $scMontant)) }}
                                    @else
                                        {{ $fmt($scMontant) }}
                                    @endif
                                </td>
                            @endif
                        </tr>
                        @endif

                        {{-- Tiers rows --}}
                        @if ($parTiers && ! empty($sc['tiers']))
                            @php $prevTiersIdx = $sectionPrevIdx['tiers'] ?? []; @endphp
                            @foreach ($sc['tiers'] as $t)
                                @php
                                    $tMontant = (float) ($t['montant'] ?? 0);
                                    $tPrev = (float) ($prevTiersIdx[$scId][$t['tiers_id'] ?? -1] ?? 0);
                                    $tVisible = $tMontant > 0 || ($mode !== 'realise' && $tPrev > 0);
                                @endphp
                                @if ($tVisible)
                                @php
                                    $__tId = (int) ($t['tiers_id'] ?? 0);
                                    $projTiers = ($mode === 'projection' && $projMatrix)
                                        ? (float) ($projMatrix->byScTiers($scId)[$__tId] ?? 0)
                                        : $tMontant;
                                @endphp
                                <tr class="cr-tiers">
                                    <td style="width:16px;"></td>
                                    <td style="padding-left:24px;">{{ $t['label'] }}</td>
                                    @if ($combinedMode)
                                        @php
                                            $projTSO = ($mode === 'projection' && $projMatrix)
                                                ? ($projMatrix->byScTiersSeanceOp($scId)[$__tId] ?? [])
                                                : [];
                                        @endphp
                                        @foreach ($operationNames as $opId => $opNom)
                                            @foreach ($seancesParOperation[$opId] ?? [] as $s)
                                                @php
                                                    $tSOVal = ($mode === 'projection' && $projMatrix)
                                                        ? (float) ($projTSO[$s][$opId] ?? 0)
                                                        : (float) ($t['seance_operations'][$s][$opId] ?? 0);
                                                @endphp
                                                <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;font-size:{{ $fontSizeSub }};">{{ $tSOVal > 0 ? $fmt($tSOVal) : '—' }}</td>
                                            @endforeach
                                            @php
                                                $tOpTot = ($mode === 'projection' && $projMatrix)
                                                    ? (float) ($projMatrix->byScTiersOp($scId)[$__tId][$opId] ?? 0)
                                                    : (float) ($t['operations'][$opId] ?? 0);
                                            @endphp
                                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;font-size:{{ $fontSizeSub }};">{{ $tOpTot > 0 ? $fmt($tOpTot) : '—' }}</td>
                                        @endforeach
                                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $projTiers > 0 ? $fmt($projTiers) : '—' }}</td>
                                    @elseif ($parOperations)
                                        @php
                                            $projTOps = ($mode === 'projection' && $projMatrix)
                                                ? ($projMatrix->byScTiersOp($scId)[$__tId] ?? [])
                                                : [];
                                        @endphp
                                        @foreach ($operationNames as $opId => $opNom)
                                            @php
                                                $tOpVal = ($mode === 'projection' && $projMatrix)
                                                    ? (float) ($projTOps[$opId] ?? 0)
                                                    : (float) ($t['operations'][$opId] ?? 0);
                                            @endphp
                                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $tOpVal > 0 ? $fmt($tOpVal) : '—' }}</td>
                                        @endforeach
                                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $projTiers > 0 ? $fmt($projTiers) : '—' }}</td>
                                    @elseif ($parSeances)
                                        @php
                                            $projTSeances = ($mode === 'projection' && $projMatrix)
                                                ? ($projMatrix->byScTiersSeance($scId)[$__tId] ?? [])
                                                : [];
                                        @endphp
                                        @foreach ($seances as $s)
                                            @php
                                                $tSeanceVal = ($mode === 'projection' && $projMatrix)
                                                    ? (float) ($projTSeances[$s] ?? 0)
                                                    : (float) ($t['seances'][$s] ?? 0);
                                            @endphp
                                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $tSeanceVal > 0 ? $fmt($tSeanceVal) : '—' }}</td>
                                        @endforeach
                                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($projTiers) }}</td>
                                    @else
                                        <td class="text-right">{{ $fmt($projTiers) }}</td>
                                    @endif
                                </tr>
                                @endif
                            @endforeach
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Section total --}}
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
            <tr class="cr-total">
                <td colspan="2">TOTAL {{ $section['label'] }}</td>
                @if ($combinedMode)
                    @foreach ($operationNames as $opId => $opNom)
                        @foreach ($seancesParOperation[$opId] ?? [] as $s)
                            @php
                                $totSO = ($mode === 'projection' && $projMatrix)
                                    ? (float) ($projMatrix->bySeanceOp()[$s][$opId] ?? 0)
                                    : (float) ($totalSectionSeanceOps[$s][$opId] ?? 0);
                            @endphp
                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;font-size:{{ $fontSizeSub }};">{{ $totSO > 0 ? $fmt($totSO) : '—' }}</td>
                        @endforeach
                        @php
                            $totOp = ($mode === 'projection' && $projMatrix)
                                ? (float) ($projMatrix->byOp()[$opId] ?? 0)
                                : 0.0;
                            if (! ($mode === 'projection' && $projMatrix)) {
                                $totOp = 0.0;
                                foreach ($section['data'] as $_cat) { $totOp += (float) ($_cat['operations'][$opId] ?? 0); }
                            }
                        @endphp
                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($totOp) }}</td>
                    @endforeach
                    @php
                        $grandTotal = ($mode === 'projection' && $projMatrix)
                            ? $projMatrix->total()
                            : $section['totalMontant'];
                        $projectedSectionTotals[$section['label']] = $grandTotal;
                        if ($mode === 'projection' && $projMatrix) {
                            $projectedSectionTotals[$section['label'].'_ops'] = $projMatrix->byOp();
                        }
                    @endphp
                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($grandTotal) }}</td>
                @elseif ($parOperations)
                    @foreach ($operationNames as $opId => $opNom)
                        @if ($mode === 'projection' && $projMatrix)
                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt((float) ($projMatrix->byOp()[$opId] ?? 0)) }}</td>
                        @else
                            @php
                                $secOpTotal = 0.0;
                                foreach ($section['data'] as $_cat) { $secOpTotal += (float) ($_cat['operations'][$opId] ?? 0); }
                            @endphp
                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($secOpTotal) }}</td>
                        @endif
                    @endforeach
                    @if ($mode === 'projection' && $projMatrix)
                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt(array_sum($projMatrix->byOp())) }}</td>
                    @else
                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($section['totalMontant']) }}</td>
                    @endif
                    @php
                        if ($mode === 'projection' && $projMatrix) {
                            $projectedSectionTotals[$section['label']] = $projMatrix->byOp();
                        }
                    @endphp
                @elseif ($parSeances)
                    @foreach ($seances as $s)
                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">
                            @if ($mode === 'projection' && $projMatrix)
                                @php
                                    $secSeanceProj = 0.0;
                                    foreach ($section['data'] as $_cat) {
                                        foreach ($_cat['sous_categories'] as $_sc) {
                                            $_scId = (int) ($_sc['sous_categorie_id'] ?? $_sc['id'] ?? 0);
                                            $secSeanceProj += (float) ($projMatrix->byScSeance()[$_scId][$s] ?? 0);
                                        }
                                    }
                                @endphp
                                {{ $secSeanceProj > 0 ? $fmt($secSeanceProj) : '—' }}
                            @else
                                {{ $fmt($totalSectionSeances[$s]) }}
                            @endif
                        </td>
                    @endforeach
                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">
                        @if ($mode === 'projection' && $projMatrix)
                            {{ $fmt($projMatrix->total()) }}
                        @else
                            {{ $fmt($section['totalMontant']) }}
                        @endif
                    </td>
                @else
                    <td class="text-right">
                        @if ($mode === 'projection' && $projMatrix)
                            {{ $fmt($projMatrix->total()) }}
                        @else
                            {{ $fmt($section['totalMontant']) }}
                        @endif
                    </td>
                @endif
            </tr>

            {{-- RÉSULTAT row inside RECETTES table for parOperations --}}
            @if ($section['label'] === 'RECETTES' && $parOperations && count($operationNames) > 1)
                @php
                    if ($combinedMode) {
                        $displayResultat = ($projectedSectionTotals['RECETTES'] ?? 0) - ($projectedSectionTotals['DÉPENSES'] ?? 0);
                    } elseif ($mode === 'projection' && ! empty($projectedSectionTotals)) {
                        $displayResultat = array_sum($projectedSectionTotals['RECETTES'] ?? []) - array_sum($projectedSectionTotals['DÉPENSES'] ?? []);
                    } else {
                        $displayResultat = $totalProduits - $totalCharges;
                    }
                @endphp
                <tr style="background:{{ $displayResultat >= 0 ? '#2E7D32' : '#B5453A' }};color:#fff;font-weight:700;">
                    <td colspan="2" style="padding:6px 8px;">RÉSULTAT</td>
                    @foreach ($operationNames as $opId => $opNom)
                        @if ($combinedMode)
                            @foreach ($seancesParOperation[$opId] ?? [] as $__s)
                                <td></td>
                            @endforeach
                        @endif
                        @php
                            if ($mode === 'projection' && $combinedMode) {
                                $opResultat = (float) ($projectedSectionTotals['RECETTES_ops'][$opId] ?? 0)
                                            - (float) ($projectedSectionTotals['DÉPENSES_ops'][$opId] ?? 0);
                            } elseif ($mode === 'projection') {
                                $opResultat = (float) ($projectedSectionTotals['RECETTES'][$opId] ?? 0)
                                            - (float) ($projectedSectionTotals['DÉPENSES'][$opId] ?? 0);
                            } else {
                                $recOp = 0.0; $depOp = 0.0;
                                foreach ($produits as $_c) { $recOp += (float) ($_c['operations'][$opId] ?? 0); }
                                foreach ($charges as $_c) { $depOp += (float) ($_c['operations'][$opId] ?? 0); }
                                $opResultat = $recOp - $depOp;
                            }
                        @endphp
                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($opResultat) }}</td>
                    @endforeach
                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($displayResultat) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
    @endforeach

    @if (! $parOperations || count($operationNames) <= 1)
        @php
            $projChargesM = $projCharges ?? null;
            $projProduitsM = $projProduits ?? null;
        @endphp
        <div class="{{ $resultatNet >= 0 ? 'cr-result-pos' : 'cr-result-neg' }}">
            @if ($mode === 'projection' && $projProduitsM)
                @php $projResultat = $projProduitsM->total() - $projChargesM->total(); @endphp
                {{ $projResultat >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }} : {{ number_format(abs($projResultat), 2, ',', ' ') }} €
            @else
                {{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }} : {{ number_format(abs($resultatNet), 2, ',', ' ') }} €
            @endif
        </div>
    @endif
@endsection
