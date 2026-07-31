@extends('pdf.rapport-layout')

@section('content')
    @php
        // Rendus directs de la vue (exports/audit) qui ne passent pas les toggles :
        // par défaut on affiche les deux colonnes (comportement d'avant les toggles).
        $compareN1 = $compareN1 ?? true;
        $compareBudget = $compareBudget ?? true;

        $fmt = fn(?float $v): string => $v !== null ? number_format($v, 2, ',', ' ') . ' €' : '—';
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'isCharge' => true, 'total' => $totalChargesN, 'totalN1' => $totalChargesN1],
               ['data' => $produits, 'label' => 'RECETTES', 'isCharge' => false, 'total' => $totalProduitsN, 'totalN1' => $totalProduitsN1]] as $section)
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            <tr class="cr-section-header">
                <td colspan="2">{{ $section['label'] }}</td>
                @if($compareN1)
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN1 }}</td>
                @endif
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN }}</td>
                @if($compareBudget)
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">Budget</td>
                <td class="text-right" style="width:80px;font-weight:400;font-size:10px;opacity:.85;">Écart</td>
                @endif
            </tr>
            @foreach ($section['data'] as $cat)
                @php
                    $scVisibles = collect($cat['comptes'])->filter(fn($sc) =>
                        $sc['montant_n'] != 0 || ($sc['montant_n1'] !== null && $sc['montant_n1'] != 0) || ($sc['budget'] !== null && $sc['budget'] != 0)
                    );
                @endphp
                @if (! $scVisibles->isEmpty())
                    <tr class="cr-cat">
                        <td colspan="2">{{ $cat['famille_nom'] }}</td>
                        @if($compareN1)
                        <td class="text-right">{!! $fmt($cat['montant_n1']) !!}</td>
                        @endif
                        <td class="text-right">{!! $fmt($cat['montant_n']) !!}</td>
                        @if($compareBudget)
                        <td class="text-right">{!! $fmt($cat['budget']) !!}</td>
                        <td class="text-right">
                            @if ($cat['budget'] !== null)
                                {{ number_format((float)$cat['montant_n'] - (float)$cat['budget'], 2, ',', ' ') }} €
                            @else
                                —
                            @endif
                        </td>
                        @endif
                    </tr>
                    @foreach ($scVisibles as $sc)
                        <tr class="cr-sub">
                            <td style="width:20px;"></td>
                            <td style="padding-left:20px;">{{ $sc['compte_nom'] }}</td>
                            @if($compareN1)
                            <td class="text-right">{!! $fmt($sc['montant_n1']) !!}</td>
                            @endif
                            <td class="text-right">{!! $fmt($sc['montant_n']) !!}</td>
                            @if($compareBudget)
                            <td class="text-right">{!! $fmt($sc['budget']) !!}</td>
                            <td class="text-right">
                                @if ($sc['budget'] !== null)
                                    {{ number_format((float)$sc['montant_n'] - (float)$sc['budget'], 2, ',', ' ') }} €
                                @else
                                    —
                                @endif
                            </td>
                            @endif
                        </tr>
                    @endforeach
                @endif
            @endforeach
            <tr class="cr-total">
                <td colspan="2">TOTAL {{ $section['label'] }}</td>
                @if($compareN1)
                <td class="text-right">{{ number_format($section['totalN1'], 2, ',', ' ') }} €</td>
                @endif
                <td class="text-right">{{ number_format($section['total'], 2, ',', ' ') }} €</td>
                @if($compareBudget)
                <td class="text-right">—</td>
                <td class="text-right">—</td>
                @endif
            </tr>
        </tbody>
    </table>
    @endforeach

    @php $resultatColor = $resultatCourant >= 0 ? '#2E7D32' : '#B5453A'; @endphp
    <table class="data-table" style="margin-top:8px;">
        <tbody>
            <tr style="background:{{ $resultatColor }};color:#fff;font-weight:700;font-size:13px;">
                <td colspan="2" style="padding:8px 10px;">RÉSULTAT</td>
                @if($compareN1)
                <td class="text-right" style="width:90px;padding:8px 10px;color:rgba(255,255,255,.6);">{{ $resultatCourantN1 != 0 ? number_format($resultatCourantN1, 2, ',', ' ').' €' : '—' }}</td>
                @endif
                <td class="text-right" style="width:90px;padding:8px 10px;">{{ number_format($resultatCourant, 2, ',', ' ') }} €</td>
                @if($compareBudget)
                <td style="width:90px;padding:8px 10px;"></td>
                <td style="width:80px;padding:8px 10px;"></td>
                @endif
            </tr>
        </tbody>
    </table>
@endsection
