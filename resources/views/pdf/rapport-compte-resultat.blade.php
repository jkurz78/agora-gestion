@extends('pdf.rapport-layout')

@section('content')
    @php
        $fmt = fn(?float $v): string => $v !== null ? number_format($v, 2, ',', ' ') . ' €' : '—';
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'isCharge' => true, 'total' => $totalChargesN],
               ['data' => $produits, 'label' => 'RECETTES', 'isCharge' => false, 'total' => $totalProduitsN]] as $section)
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            <tr class="cr-section-header">
                <td colspan="2">{{ $section['label'] }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN1 }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">Budget</td>
                <td class="text-right" style="width:80px;font-weight:400;font-size:10px;opacity:.85;">Écart</td>
            </tr>
            @foreach ($section['data'] as $cat)
                @php
                    $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) =>
                        $sc['montant_n'] > 0 || ($sc['montant_n1'] !== null && $sc['montant_n1'] > 0) || ($sc['budget'] !== null && $sc['budget'] > 0)
                    );
                @endphp
                @if (! $scVisibles->isEmpty())
                    <tr class="cr-cat">
                        <td colspan="2">{{ $cat['label'] }}</td>
                        <td class="text-right">{!! $fmt($cat['montant_n1']) !!}</td>
                        <td class="text-right">{!! $fmt($cat['montant_n']) !!}</td>
                        <td class="text-right">{!! $fmt($cat['budget']) !!}</td>
                        <td class="text-right">
                            @if ($cat['budget'] !== null)
                                {{ number_format((float)$cat['montant_n'] - (float)$cat['budget'], 2, ',', ' ') }} €
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @foreach ($scVisibles as $sc)
                        <tr class="cr-sub">
                            <td style="width:20px;"></td>
                            <td style="padding-left:20px;">{{ $sc['label'] }}</td>
                            <td class="text-right">{!! $fmt($sc['montant_n1']) !!}</td>
                            <td class="text-right">{!! $fmt($sc['montant_n']) !!}</td>
                            <td class="text-right">{!! $fmt($sc['budget']) !!}</td>
                            <td class="text-right">
                                @if ($sc['budget'] !== null)
                                    {{ number_format((float)$sc['montant_n'] - (float)$sc['budget'], 2, ',', ' ') }} €
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
            <tr class="cr-total">
                <td colspan="2">TOTAL {{ $section['label'] }}</td>
                <td class="text-right">—</td>
                <td class="text-right">{{ number_format($section['total'], 2, ',', ' ') }} €</td>
                <td class="text-right">—</td>
                <td class="text-right">—</td>
            </tr>
        </tbody>
    </table>
    @endforeach

    <div class="{{ $resultatNet >= 0 ? 'cr-result-pos' : 'cr-result-neg' }}">
        {{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }} : {{ number_format(abs($resultatNet), 2, ',', ' ') }} €
    </div>
@endsection
