@extends('pdf.rapport-layout')

@section('styles')
    .cr-tiers td { background: #fff; color: #666; font-size: 10px; border-bottom: 1px solid #f0f0f0; }
@endsection

@section('content')
    @php
        $fmt = fn(float $v): string => number_format($v, 2, ',', ' ') . ' €';
        $colCount = $parSeances ? count($seances) + 3 : 3;
        if ($parTiers) $colCount++;
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'totalMontant' => $totalCharges],
               ['data' => $produits, 'label' => 'RECETTES', 'totalMontant' => $totalProduits]] as $section)
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            {{-- Column header --}}
            <tr class="cr-section-header">
                <td colspan="2"></td>
                @if ($parSeances)
                    @foreach ($seances as $s)
                        <td class="text-right" style="width:70px;font-size:9px;opacity:.85;">{{ $s === 0 ? 'Hors S.' : 'S'.$s }}</td>
                    @endforeach
                    <td class="text-right" style="width:70px;font-size:9px;opacity:.85;">Total</td>
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
                                <td class="text-right">{{ ($cat['seances'][$s] ?? 0) > 0 ? $fmt($cat['seances'][$s]) : '—' }}</td>
                            @endforeach
                            <td class="text-right">{{ $fmt($cat['total']) }}</td>
                        @else
                            <td class="text-right">{{ $fmt($cat['montant']) }}</td>
                        @endif
                    </tr>

                    @foreach ($scVisibles as $sc)
                        {{-- Sub-category row --}}
                        <tr class="cr-sub">
                            <td style="width:20px;"></td>
                            <td>{{ $sc['label'] }}</td>
                            @if ($parSeances)
                                @foreach ($seances as $s)
                                    <td class="text-right">{{ ($sc['seances'][$s] ?? 0) > 0 ? $fmt($sc['seances'][$s]) : '—' }}</td>
                                @endforeach
                                <td class="text-right fw-bold">{{ $fmt($sc['total']) }}</td>
                            @else
                                <td class="text-right">{{ $fmt($sc['montant']) }}</td>
                            @endif
                        </tr>

                        {{-- Tiers rows --}}
                        @if ($parTiers && ! empty($sc['tiers']))
                            @foreach ($sc['tiers'] as $t)
                                @if (($parSeances ? ($t['total'] ?? 0) : ($t['montant'] ?? 0)) > 0)
                                <tr class="cr-tiers">
                                    <td style="width:20px;"></td>
                                    <td style="padding-left:30px;">{{ $t['label'] }}</td>
                                    @if ($parSeances)
                                        @foreach ($seances as $s)
                                            <td class="text-right">{{ ($t['seances'][$s] ?? 0) > 0 ? $fmt($t['seances'][$s]) : '—' }}</td>
                                        @endforeach
                                        <td class="text-right">{{ $fmt($t['total']) }}</td>
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
                        <td class="text-right">{{ $fmt($totalSectionSeances[$s]) }}</td>
                    @endforeach
                    <td class="text-right">{{ $fmt($section['totalMontant']) }}</td>
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
