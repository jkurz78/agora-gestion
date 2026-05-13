@extends('pdf.rapport-layout')

@section('styles')
    .cr-tiers td { background: #fff; color: #666; border-bottom: 1px solid #f0f0f0; }
@endsection

@section('content')
    @php
        $previsionnel = $previsionnel ?? false;
        $nSeances = $parSeances ? count($seances) : 0;
        // Taille adaptative selon le nombre de colonnes
        if ($nSeances > 10) {
            $colWidth = '40px'; $fontSize = '7px'; $fontSizeSub = '6px'; $fontSizeHeader = '6px'; $pad = '2px 3px';
        } elseif ($nSeances > 5) {
            $colWidth = '52px'; $fontSize = '8px'; $fontSizeSub = '7px'; $fontSizeHeader = '7px'; $pad = '3px 4px';
        } else {
            $colWidth = '70px'; $fontSize = '10px'; $fontSizeSub = '9px'; $fontSizeHeader = '8px'; $pad = '4px 6px';
        }
        $fmt = fn(float $v): string => number_format($v, 2, ',', ' ') . ' €';
        $colCount = $parSeances ? count($seances) + 3 : 3;
        if ($parTiers) $colCount++;

        // Build flat previsions lookup: sc_id => { montant, seances: {num => float} }
        $buildPrevIdx = function (array $hierarchy): array {
            $idx = [];
            foreach ($hierarchy as $cat) {
                foreach ($cat['sous_categories'] as $sc) {
                    $scId = (int) $sc['id'];
                    $idx[$scId] = [
                        'montant' => (float) ($sc['montant'] ?? $sc['total'] ?? 0),
                        'seances' => $sc['seances'] ?? [],
                    ];
                }
            }
            return $idx;
        };

        $prevChargesIdx  = $previsionnel ? $buildPrevIdx($previsionsCharges  ?? []) : [];
        $prevProduitsIdx = $previsionnel ? $buildPrevIdx($previsionsProduits ?? []) : [];

        // Renders a stacked Prévu/Réalisé/Écart cell for DOMPDF
        $renderCellPrev = function (float $realise, float $prevu): string {
            $r = number_format($realise, 2, ',', ' ');
            $p = number_format($prevu,   2, ',', ' ');
            $ecart = $realise - $prevu;
            $couleur = abs($ecart) < 0.01 ? '#6c757d' : ($ecart >= 0 ? '#2E7D32' : '#B5453A');
            $signe = $ecart > 0 ? '+' : '';
            $e = $signe . number_format($ecart, 2, ',', ' ');
            return '<div style="line-height:1.3;font-size:9px">'
                . '<div style="color:#6c757d;font-size:8px">' . $p . '</div>'
                . '<div style="font-weight:600">' . $r . '</div>'
                . '<div style="color:' . $couleur . ';font-size:8px">' . $e . '</div>'
                . '</div>';
        };
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'totalMontant' => $totalCharges, 'prevIdx' => $prevChargesIdx],
               ['data' => $produits, 'label' => 'RECETTES', 'totalMontant' => $totalProduits, 'prevIdx' => $prevProduitsIdx]] as $section)
    <table class="data-table" style="margin-bottom:14px;font-size:{{ $fontSize }};">
        <tbody>
            {{-- Column header --}}
            <tr class="cr-section-header">
                <td colspan="2"></td>
                @if ($parSeances)
                    @foreach ($seances as $s)
                        <td class="text-right" style="width:{{ $colWidth }};font-size:{{ $fontSizeHeader }};opacity:.85;padding:{{ $pad }};">{{ $s === 0 ? 'Hors S.' : 'S'.$s }}</td>
                    @endforeach
                    <td class="text-right" style="width:{{ $colWidth }};font-size:{{ $fontSizeHeader }};opacity:.85;padding:{{ $pad }};">Total</td>
                @else
                    <td class="text-right" style="width:100px;font-size:10px;opacity:.85;">Montant</td>
                @endif
            </tr>
            <tr class="cr-section-header">
                <td colspan="{{ $colCount }}">{{ $section['label'] }}</td>
            </tr>

            @foreach ($section['data'] as $cat)
                @php
                    $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) =>
                        ($parSeances ? ($sc['total'] ?? 0) : ($sc['montant'] ?? 0)) > 0
                    );
                @endphp
                @if (! $scVisibles->isEmpty())
                    {{-- Category row --}}
                    <tr class="cr-cat">
                        <td colspan="2">{{ $cat['label'] }}</td>
                        @if ($parSeances)
                            @foreach ($seances as $s)
                                <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ ($cat['seances'][$s] ?? 0) > 0 ? $fmt($cat['seances'][$s]) : '—' }}</td>
                            @endforeach
                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($cat['total']) }}</td>
                        @else
                            <td class="text-right">{{ $fmt($cat['montant']) }}</td>
                        @endif
                    </tr>

                    @foreach ($scVisibles as $sc)
                        @php
                            $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                            $prevSc = $section['prevIdx'][$scId] ?? ['montant' => 0.0, 'seances' => []];
                        @endphp
                        {{-- Sub-category row --}}
                        <tr class="cr-sub">
                            <td style="width:16px;"></td>
                            <td>{{ $sc['label'] }}</td>
                            @if ($parSeances)
                                @foreach ($seances as $s)
                                    @php
                                        $scReal = (float) ($sc['seances'][$s] ?? 0);
                                        $scPrev = (float) ($prevSc['seances'][$s] ?? 0);
                                    @endphp
                                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">
                                        @if ($previsionnel)
                                            {!! $renderCellPrev($scReal, $scPrev) !!}
                                        @else
                                            {{ $scReal > 0 ? $fmt($scReal) : '—' }}
                                        @endif
                                    </td>
                                @endforeach
                                @php $scTotalReal = (float) ($sc['total'] ?? 0); @endphp
                                <td class="text-right fw-bold" style="padding:{{ $pad }};white-space:nowrap;">
                                    @if ($previsionnel)
                                        {!! $renderCellPrev($scTotalReal, (float) $prevSc['montant']) !!}
                                    @else
                                        {{ $fmt($scTotalReal) }}
                                    @endif
                                </td>
                            @else
                                @php $scMontant = (float) ($sc['montant'] ?? 0); @endphp
                                <td class="text-right">
                                    @if ($previsionnel)
                                        {!! $renderCellPrev($scMontant, (float) $prevSc['montant']) !!}
                                    @else
                                        {{ $fmt($scMontant) }}
                                    @endif
                                </td>
                            @endif
                        </tr>

                        {{-- Tiers rows --}}
                        @if ($parTiers && ! empty($sc['tiers']))
                            @foreach ($sc['tiers'] as $t)
                                @if (($parSeances ? ($t['total'] ?? 0) : ($t['montant'] ?? 0)) > 0)
                                <tr class="cr-tiers">
                                    <td style="width:16px;"></td>
                                    <td style="padding-left:24px;">{{ $t['label'] }}</td>
                                    @if ($parSeances)
                                        @foreach ($seances as $s)
                                            <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ ($t['seances'][$s] ?? 0) > 0 ? $fmt($t['seances'][$s]) : '—' }}</td>
                                        @endforeach
                                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($t['total']) }}</td>
                                    @else
                                        <td class="text-right">{{ $fmt($t['montant']) }}</td>
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
            @endphp
            <tr class="cr-total">
                <td colspan="2">TOTAL {{ $section['label'] }}</td>
                @if ($parSeances)
                    @foreach ($seances as $s)
                        <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($totalSectionSeances[$s]) }}</td>
                    @endforeach
                    <td class="text-right" style="padding:{{ $pad }};white-space:nowrap;">{{ $fmt($section['totalMontant']) }}</td>
                @else
                    <td class="text-right">{{ $fmt($section['totalMontant']) }}</td>
                @endif
            </tr>
        </tbody>
    </table>
    @endforeach

    <div class="{{ $resultatNet >= 0 ? 'cr-result-pos' : 'cr-result-neg' }}">
        {{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }} : {{ number_format(abs($resultatNet), 2, ',', ' ') }} €
    </div>
@endsection
