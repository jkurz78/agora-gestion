@extends('pdf.rapport-layout')

@section('styles')
    .balance-meta { margin-bottom: 10px; font-size: 11px; color: #6c757d; }
    .balance-meta strong { color: #3d5473; }
    .balance-table th {
        background: #3d5473;
        color: #fff;
        border-bottom: none;
        padding: 6px 7px;
        font-size: 10px;
    }
    .balance-table td {
        padding: 5px 7px;
        font-size: 10px;
    }
    .balance-account { font-weight: 700; color: #1e3a5f; }
    .balance-tier { color: #6c757d; font-size: 9px; margin-top: 2px; }
    .balance-total td {
        background: #5a7fa8;
        color: #fff;
        font-weight: 700;
        border-bottom: none;
    }
    .balance-zero { color: #adb5bd; }
@endsection

@section('content')
    @php
        $fmt = function (int $centimes): string {
            if ($centimes === 0) {
                return '—';
            }

            return number_format($centimes / 100, 2, ',', ' ') . ' €';
        };

        $lignes = $balance['lignes'];
        $totaux = $balance['totaux'];
        $totalSoldeOuvertureDebit = collect($lignes)->sum('solde_ouverture_debit_centimes');
        $totalSoldeOuvertureCredit = collect($lignes)->sum('solde_ouverture_credit_centimes');
    @endphp

    <div class="balance-meta">
        <strong>Période :</strong> {{ $subtitle }}
        &nbsp;·&nbsp;
        <strong>Comptes :</strong> {{ $comptes }}
    </div>

    <table class="data-table balance-table">
        <thead>
            <tr>
                <th rowspan="2" style="width:95px;">Compte</th>
                <th rowspan="2">Intitulé</th>
                @if($colonnes >= 4)
                    <th colspan="2" class="text-center">Ouverture</th>
                @endif
                @if($colonnes === 6)
                    <th colspan="2" class="text-center">Mouvements</th>
                @endif
                <th colspan="2" class="text-center">Solde final</th>
            </tr>
            <tr>
                @if($colonnes >= 4)
                    <th class="text-right" style="width:75px;">Débit</th>
                    <th class="text-right" style="width:75px;">Crédit</th>
                @endif
                @if($colonnes === 6)
                    <th class="text-right" style="width:75px;">Débit</th>
                    <th class="text-right" style="width:75px;">Crédit</th>
                @endif
                <th class="text-right" style="width:75px;">Débit</th>
                <th class="text-right" style="width:75px;">Crédit</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $ligne)
                <tr>
                    <td>
                        <div class="balance-account">{{ $ligne['numero_compte'] }}</div>
                        @if($ligne['tiers'] !== null)
                            <div class="balance-tier">{{ $ligne['tiers'] }}</div>
                        @endif
                    </td>
                    <td>{{ $ligne['intitule_compte'] }}</td>
                    @if($colonnes >= 4)
                        <td class="text-right"><span class="{{ $ligne['solde_ouverture_debit_centimes'] === 0 ? 'balance-zero' : '' }}">{{ $fmt((int) $ligne['solde_ouverture_debit_centimes']) }}</span></td>
                        <td class="text-right"><span class="{{ $ligne['solde_ouverture_credit_centimes'] === 0 ? 'balance-zero' : '' }}">{{ $fmt((int) $ligne['solde_ouverture_credit_centimes']) }}</span></td>
                    @endif
                    @if($colonnes === 6)
                        <td class="text-right"><span class="{{ $ligne['mouvement_debit_centimes'] === 0 ? 'balance-zero' : '' }}">{{ $fmt((int) $ligne['mouvement_debit_centimes']) }}</span></td>
                        <td class="text-right"><span class="{{ $ligne['mouvement_credit_centimes'] === 0 ? 'balance-zero' : '' }}">{{ $fmt((int) $ligne['mouvement_credit_centimes']) }}</span></td>
                    @endif
                    <td class="text-right"><span class="{{ $ligne['solde_fin_debit_centimes'] === 0 ? 'balance-zero' : '' }}">{{ $fmt((int) $ligne['solde_fin_debit_centimes']) }}</span></td>
                    <td class="text-right"><span class="{{ $ligne['solde_fin_credit_centimes'] === 0 ? 'balance-zero' : '' }}">{{ $fmt((int) $ligne['solde_fin_credit_centimes']) }}</span></td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 2 + $colonnes }}" class="text-center text-muted" style="padding:16px;">
                        Aucune ligne pour cette sélection.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if(count($lignes) > 0)
            <tfoot>
                <tr class="balance-total">
                    <td colspan="2">TOTAL</td>
                    @if($colonnes >= 4)
                        <td class="text-right">{{ $fmt((int) $totalSoldeOuvertureDebit) }}</td>
                        <td class="text-right">{{ $fmt((int) $totalSoldeOuvertureCredit) }}</td>
                    @endif
                    @if($colonnes === 6)
                        <td class="text-right">{{ $fmt((int) $totaux['mouvement_debit_centimes']) }}</td>
                        <td class="text-right">{{ $fmt((int) $totaux['mouvement_credit_centimes']) }}</td>
                    @endif
                    <td class="text-right">{{ $fmt((int) $totaux['solde_fin_debit_centimes']) }}</td>
                    <td class="text-right">{{ $fmt((int) $totaux['solde_fin_credit_centimes']) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
@endsection
