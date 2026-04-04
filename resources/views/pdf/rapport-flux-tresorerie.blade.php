@extends('pdf.rapport-layout')

@section('styles')
    .ft-section td { background: #3d5473; color: #fff; font-weight: 700; font-size: 13px; padding: 8px 12px; border: none; }
    .ft-row td { padding: 8px 12px; font-size: 12px; border-bottom: 1px solid #e2e8f0; }
    .ft-row-bold td { padding: 8px 12px; font-size: 12px; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
    .ft-total td { background: #5a7fa8; color: #fff; font-weight: 700; font-size: 13px; padding: 9px 12px; border: none; }
    .ft-result td { background: #3d5473; color: #fff; font-weight: 700; font-size: 14px; padding: 10px 12px; border: none; }
    .ft-rappr td { background: #f7f9fc; padding: 6px 12px; font-size: 12px; border-bottom: 1px solid #e2e8f0; }
    .ft-rappr-detail td { padding: 4px 12px 4px 36px; font-size: 11px; color: #666; border-bottom: 1px solid #f0f0f0; }
    .ft-rappr-result td { background: #dce6f0; font-weight: 700; padding: 8px 12px; font-size: 13px; }
@endsection

@section('content')
    @php
        $fmt = fn(float $v): string => number_format($v, 2, ',', ' ') . ' €';
    @endphp

    {{-- Status badge --}}
    @if ($exercice['is_cloture'])
        <div style="background:#d4edda;color:#155724;padding:6px 12px;font-size:11px;margin-bottom:10px;border-radius:4px;">
            Rapport définitif — Exercice {{ $exercice['label'] }} clôturé le {{ $exercice['date_cloture'] }}
        </div>
    @else
        <div style="background:#d1ecf1;color:#0c5460;padding:6px 12px;font-size:11px;margin-bottom:10px;border-radius:4px;">
            Rapport provisoire — Exercice {{ $exercice['label'] }} en cours
        </div>
    @endif

    {{-- Synthèse + Mensuel --}}
    <table class="data-table" style="margin-bottom:14px;">
        <tbody>
            <tr class="ft-section">
                <td></td>
                <td class="text-right" style="width:110px;font-weight:400;font-size:10px;">Recettes</td>
                <td class="text-right" style="width:110px;font-weight:400;font-size:10px;">Dépenses</td>
                <td class="text-right" style="width:110px;font-weight:400;font-size:10px;">Solde (R-D)</td>
                <td class="text-right" style="width:130px;font-weight:400;font-size:10px;">Trésorerie cumulée</td>
            </tr>

            <tr class="ft-row-bold">
                <td>Solde de trésorerie au {{ \Carbon\Carbon::parse($exercice['date_debut'])->translatedFormat('j F Y') }}</td>
                <td></td><td></td><td></td>
                <td class="text-right fw-bold">{{ $fmt($synthese['solde_ouverture']) }}</td>
            </tr>

            @foreach ($mensuel as $m)
                <tr class="ft-row">
                    <td style="padding-left:24px;">{{ $m['mois'] }}</td>
                    <td class="text-right">{{ $fmt($m['recettes']) }}</td>
                    <td class="text-right">{{ $fmt($m['depenses']) }}</td>
                    <td class="text-right" style="color:{{ $m['solde'] >= 0 ? '#2E7D32' : '#B5453A' }}">
                        {{ $m['solde'] >= 0 ? '+' : '' }}{{ $fmt($m['solde']) }}
                    </td>
                    <td class="text-right fw-bold">{{ $fmt($m['cumul']) }}</td>
                </tr>
            @endforeach

            <tr class="ft-total">
                <td>TOTAL</td>
                <td class="text-right">{{ $fmt($synthese['total_recettes']) }}</td>
                <td class="text-right">{{ $fmt($synthese['total_depenses']) }}</td>
                <td class="text-right">{{ $synthese['variation'] >= 0 ? '+' : '' }}{{ $fmt($synthese['variation']) }}</td>
                <td class="text-right">{{ $fmt($synthese['solde_theorique']) }}</td>
            </tr>

            <tr class="ft-result">
                <td colspan="4">Solde de trésorerie théorique au {{ \Carbon\Carbon::parse($exercice['date_fin'])->translatedFormat('j F Y') }}</td>
                <td class="text-right">{{ $fmt($synthese['solde_theorique']) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Rapprochement --}}
    <table class="data-table">
        <tbody>
            <tr class="ft-section">
                <td colspan="2">Rapprochement bancaire</td>
            </tr>
            <tr class="ft-rappr">
                <td>Solde théorique</td>
                <td class="text-right" style="width:150px;">{{ $fmt($rapprochement['solde_theorique']) }}</td>
            </tr>
            <tr class="ft-rappr">
                <td style="padding-left:24px;">− Recettes non pointées ({{ $rapprochement['nb_recettes_non_pointees'] }})</td>
                <td class="text-right">{{ $fmt($rapprochement['recettes_non_pointees']) }}</td>
            </tr>
            @foreach (collect($ecritures_non_pointees)->where('type', 'recette') as $e)
                <tr class="ft-rappr-detail">
                    <td>{{ $e['date'] }} · {{ $e['tiers'] }} · {{ $e['libelle'] }}</td>
                    <td class="text-right">{{ $fmt($e['montant']) }}</td>
                </tr>
            @endforeach
            <tr class="ft-rappr">
                <td style="padding-left:24px;">+ Dépenses non pointées ({{ $rapprochement['nb_depenses_non_pointees'] }})</td>
                <td class="text-right">{{ $fmt($rapprochement['depenses_non_pointees']) }}</td>
            </tr>
            @foreach (collect($ecritures_non_pointees)->where('type', 'depense') as $e)
                <tr class="ft-rappr-detail">
                    <td>{{ $e['date'] }} · {{ $e['tiers'] }} · {{ $e['libelle'] }}</td>
                    <td class="text-right">{{ $fmt($e['montant']) }}</td>
                </tr>
            @endforeach
            @foreach ($rapprochement['comptes_systeme'] as $cs)
                <tr class="ft-rappr">
                    <td style="padding-left:24px;">− {{ $cs['nom'] }} ({{ $cs['nb_ecritures'] }} écr.)</td>
                    <td class="text-right">{{ $fmt($cs['solde']) }}</td>
                </tr>
                @foreach ($cs['ecritures'] as $e)
                    <tr class="ft-rappr-detail">
                        <td>{{ $e['date'] }} · {{ $e['tiers'] }} · {{ $e['libelle'] }}</td>
                        <td class="text-right">{{ $fmt($e['montant']) }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr class="ft-rappr-result">
                <td>= Solde bancaire réel</td>
                <td class="text-right">{{ $fmt($rapprochement['solde_reel']) }}</td>
            </tr>
        </tbody>
    </table>
@endsection
