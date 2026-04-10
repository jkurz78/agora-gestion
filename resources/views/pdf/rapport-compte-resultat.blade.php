@extends('pdf.rapport-layout')

@section('content')
    @php
        $fmt = fn(?float $v): string => $v !== null ? number_format($v, 2, ',', ' ') . ' €' : '—';
        $hasAdjustments = $provisions->isNotEmpty() || $provisionsN1->isNotEmpty() || $extournes->isNotEmpty() || $extournesN1->isNotEmpty();

        // Merge extournes N and N-1 by key (libelle|sous_categorie_id)
        $extournesMerged = collect();
        foreach ($extournesN1 as $e) {
            $key = $e['libelle'] . '|' . $e['sous_categorie_id'];
            $extournesMerged[$key] = ['libelle' => $e['libelle'], 'sous_categorie_nom' => $e['sous_categorie_nom'], 'montant_n1' => $e['montant_signe'], 'montant_n' => null];
        }
        foreach ($extournes as $e) {
            $key = $e['libelle'] . '|' . $e['sous_categorie_id'];
            if ($extournesMerged->has($key)) {
                $extournesMerged[$key] = array_merge($extournesMerged[$key], ['montant_n' => $e['montant_signe']]);
            } else {
                $extournesMerged[$key] = ['libelle' => $e['libelle'], 'sous_categorie_nom' => $e['sous_categorie_nom'], 'montant_n1' => null, 'montant_n' => $e['montant_signe']];
            }
        }

        // Merge provisions N and N-1 by key (libelle|sous_categorie_id)
        $provisionsMerged = collect();
        foreach ($provisionsN1 as $p) {
            $key = $p['libelle'] . '|' . $p['sous_categorie_id'];
            $provisionsMerged[$key] = ['libelle' => $p['libelle'], 'sous_categorie_nom' => $p['sous_categorie_nom'], 'montant_n1' => $p['montant_signe'], 'montant_n' => null];
        }
        foreach ($provisions as $p) {
            $key = $p['libelle'] . '|' . $p['sous_categorie_id'];
            if ($provisionsMerged->has($key)) {
                $provisionsMerged[$key] = array_merge($provisionsMerged[$key], ['montant_n' => $p['montant_signe']]);
            } else {
                $provisionsMerged[$key] = ['libelle' => $p['libelle'], 'sous_categorie_nom' => $p['sous_categorie_nom'], 'montant_n1' => null, 'montant_n' => $p['montant_signe']];
            }
        }
    @endphp

    @if ($extournes->isNotEmpty() || $extournesN1->isNotEmpty())
    {{-- Extournes provisions N-1 --}}
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            <tr class="cr-section-header">
                <td colspan="2">EXTOURNES PROVISIONS N−1</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN1 }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">Budget</td>
                <td class="text-right" style="width:80px;font-weight:400;font-size:10px;opacity:.85;">Écart</td>
            </tr>
            @foreach ($extournesMerged as $ext)
            <tr class="cr-sub">
                <td style="width:20px;"></td>
                <td>{{ $ext['libelle'] }} ({{ $ext['sous_categorie_nom'] }})</td>
                <td class="text-right" style="width:90px;">{!! $fmt($ext['montant_n1']) !!}</td>
                <td class="text-right" style="width:90px;">{!! $fmt($ext['montant_n']) !!}</td>
                <td class="text-right" style="width:90px;">—</td>
                <td class="text-right" style="width:80px;">—</td>
            </tr>
            @endforeach
            <tr class="cr-total">
                <td colspan="2">TOTAL EXTOURNES</td>
                <td class="text-right" style="width:90px;">{!! $fmt($totalExtournesN1) !!}</td>
                <td class="text-right" style="width:90px;">{!! $fmt($totalExtournes) !!}</td>
                <td class="text-right" style="width:90px;">—</td>
                <td class="text-right" style="width:80px;">—</td>
            </tr>
        </tbody>
    </table>
    @endif

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'isCharge' => true, 'total' => $totalChargesN, 'totalN1' => $totalChargesN1],
               ['data' => $produits, 'label' => 'RECETTES', 'isCharge' => false, 'total' => $totalProduitsN, 'totalN1' => $totalProduitsN1]] as $section)
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
                <td class="text-right">{{ number_format($section['totalN1'], 2, ',', ' ') }} €</td>
                <td class="text-right">{{ number_format($section['total'], 2, ',', ' ') }} €</td>
                <td class="text-right">—</td>
                <td class="text-right">—</td>
            </tr>
        </tbody>
    </table>
    @endforeach

    @if ($hasAdjustments)
    {{-- Résultat brut (avant provisions) --}}
    <table class="data-table" style="margin-bottom:8px;">
        <tbody>
            <tr class="cr-total">
                <td colspan="2">RÉSULTAT BRUT</td>
                <td class="text-right" style="width:90px;">{{ number_format($resultatBrutN1, 2, ',', ' ') }} €</td>
                <td class="text-right" style="width:90px;">{{ number_format($resultatBrut, 2, ',', ' ') }} €</td>
                <td class="text-right" style="width:90px;">—</td>
                <td class="text-right" style="width:80px;">—</td>
            </tr>
        </tbody>
    </table>

    @if ($provisions->isNotEmpty() || $provisionsN1->isNotEmpty())
    {{-- Provisions fin d'exercice --}}
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            <tr class="cr-section-header">
                <td colspan="2">PROVISIONS FIN D'EXERCICE</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN1 }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">{{ $labelN }}</td>
                <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">Budget</td>
                <td class="text-right" style="width:80px;font-weight:400;font-size:10px;opacity:.85;">Écart</td>
            </tr>
            @foreach ($provisionsMerged as $prov)
            <tr class="cr-sub">
                <td style="width:20px;"></td>
                <td>{{ $prov['libelle'] }} ({{ $prov['sous_categorie_nom'] }})</td>
                <td class="text-right" style="width:90px;">{!! $fmt($prov['montant_n1']) !!}</td>
                <td class="text-right" style="width:90px;">{!! $fmt($prov['montant_n']) !!}</td>
                <td class="text-right" style="width:90px;">—</td>
                <td class="text-right" style="width:80px;">—</td>
            </tr>
            @endforeach
            <tr class="cr-total">
                <td colspan="2">TOTAL PROVISIONS</td>
                <td class="text-right" style="width:90px;">{!! $fmt($totalProvisionsN1) !!}</td>
                <td class="text-right" style="width:90px;">{!! $fmt($totalProvisions) !!}</td>
                <td class="text-right" style="width:90px;">—</td>
                <td class="text-right" style="width:80px;">—</td>
            </tr>
        </tbody>
    </table>
    @endif

    @php $resultatColor = $resultatNet >= 0 ? '#2E7D32' : '#B5453A'; @endphp
    <table class="data-table" style="margin-top:8px;">
        <tbody>
            <tr style="background:{{ $resultatColor }};color:#fff;font-weight:700;font-size:13px;">
                <td colspan="2" style="padding:8px 10px;">RÉSULTAT AJUSTÉ</td>
                <td class="text-right" style="width:90px;padding:8px 10px;color:rgba(255,255,255,.6);">{{ number_format($resultatNetN1, 2, ',', ' ') }} €</td>
                <td class="text-right" style="width:90px;padding:8px 10px;">{{ number_format($resultatNet, 2, ',', ' ') }} €</td>
                <td style="width:90px;padding:8px 10px;"></td>
                <td style="width:80px;padding:8px 10px;"></td>
            </tr>
        </tbody>
    </table>

    @else
    {{-- Pas de provisions ni extournes --}}
    @php $resultatColor = $resultatNet >= 0 ? '#2E7D32' : '#B5453A'; @endphp
    <table class="data-table" style="margin-top:8px;">
        <tbody>
            <tr style="background:{{ $resultatColor }};color:#fff;font-weight:700;font-size:13px;">
                <td colspan="2" style="padding:8px 10px;">RÉSULTAT</td>
                <td class="text-right" style="width:90px;padding:8px 10px;color:rgba(255,255,255,.6);">&mdash;</td>
                <td class="text-right" style="width:90px;padding:8px 10px;">{{ number_format($resultatNet, 2, ',', ' ') }} €</td>
                <td style="width:90px;padding:8px 10px;"></td>
                <td style="width:80px;padding:8px 10px;"></td>
            </tr>
        </tbody>
    </table>
    @endif
@endsection
