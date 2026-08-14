@extends('pdf.rapport-layout')

@section('styles')
    .immo-meta { margin-bottom: 10px; font-size: 11px; color: #6c757d; }
    .immo-meta strong { color: #3d5473; }
    .immo-table th {
        background: #3d5473;
        color: #fff;
        border-bottom: none;
        padding: 6px 7px;
        font-size: 10px;
    }
    .immo-table td {
        padding: 5px 7px;
        font-size: 10px;
    }
    .immo-numero { font-weight: 700; color: #1e3a5f; }
    .immo-compte { color: #6c757d; font-size: 9px; }
    .immo-total td {
        background: #5a7fa8;
        color: #fff;
        font-weight: 700;
        border-bottom: none;
    }
@endsection

@section('content')
    @php
        $fmt = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ') . ' €';

        $lignes = $livre['lignes'];
        $totaux = $livre['totaux'];
    @endphp

    <div class="immo-meta">
        <strong>Exercice :</strong> {{ $subtitle }}
        &nbsp;·&nbsp;
        <strong>Fiches au registre :</strong> {{ count($lignes) }}
    </div>

    @if (empty($lignes))
        <p>Aucune immobilisation au registre pour cet exercice.</p>
    @else
        <table class="data-table immo-table">
            <thead>
                <tr>
                    <th style="width:70px;">N°</th>
                    <th>Bien</th>
                    <th class="text-end" style="width:35px;">Qté</th>
                    <th style="width:70px;">Acquisition</th>
                    <th style="width:75px;">Mise en service</th>
                    <th style="width:55px;">Durée</th>
                    <th class="text-end" style="width:80px;">Valeur brute</th>
                    <th class="text-end" style="width:80px;">Dotation</th>
                    <th class="text-end" style="width:85px;">Cumul amort.</th>
                    <th class="text-end" style="width:85px;">Valeur nette</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lignes as $ligne)
                    <tr>
                        <td class="immo-numero">{{ $ligne['numero'] }}</td>
                        <td>
                            {{ $ligne['libelle'] }}
                            <div class="immo-compte">{{ $ligne['compte'] }}</div>
                        </td>
                        <td class="text-end">{{ $ligne['quantite'] }}</td>
                        <td>{{ $ligne['date_acquisition']->format('d/m/Y') }}</td>
                        <td>{{ $ligne['date_mise_en_service']->format('d/m/Y') }}</td>
                        <td>{{ $ligne['duree_label'] }}</td>
                        <td class="text-end">{{ $fmt($ligne['montant_acquisition_centimes']) }}</td>
                        <td class="text-end">{{ $fmt($ligne['dotation_centimes']) }}</td>
                        <td class="text-end">{{ $fmt($ligne['cumul_centimes']) }}</td>
                        <td class="text-end">{{ $fmt($ligne['vnc_centimes']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="immo-total">
                    <td colspan="6">TOTAL</td>
                    <td class="text-end">{{ $fmt($totaux['brut']) }}</td>
                    <td class="text-end">{{ $fmt($totaux['dotation']) }}</td>
                    <td class="text-end">{{ $fmt($totaux['cumul']) }}</td>
                    <td class="text-end">{{ $fmt($totaux['vnc']) }}</td>
                </tr>
            </tfoot>
        </table>

        <p class="immo-meta" style="margin-top:10px;">
            Valeurs calculées d’après le plan d’amortissement au dernier jour de l’exercice.
            Tant que les dotations de l’exercice ne sont pas générées, elles peuvent différer du grand livre.
        </p>
    @endif
@endsection
