<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapprochement bancaire #{{ $rapprochement->id }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            margin: 15mm;
            color: #212529;
            line-height: 1.4;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table.layout {
            border-collapse: collapse;
            width: 100%;
        }
        table.layout td {
            vertical-align: top;
            padding: 0;
        }

        /* Header */
        .header {
            margin-bottom: 18px;
        }
        .header .logo {
            max-height: 60px;
            max-width: 120px;
        }
        .association-name {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .association-address {
            font-size: 10px;
            color: #6c757d;
        }
        .doc-title {
            font-size: 18px;
            font-weight: bold;
            color: #0d6efd;
            text-align: right;
        }
        .doc-date {
            font-size: 10px;
            color: #6c757d;
            text-align: right;
            margin-top: 4px;
        }

        /* Info block */
        .info-block {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }
        .info-block table.layout td {
            padding: 3px 8px 3px 0;
            width: 25%;
        }
        .info-label {
            font-size: 9px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 2px;
        }
        .info-value {
            font-size: 11px;
            font-weight: bold;
        }

        /* Soldes */
        .soldes {
            margin-bottom: 16px;
        }
        .solde-card {
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 8px 10px;
            text-align: center;
        }
        .solde-card .solde-label {
            font-size: 9px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .solde-card .solde-value {
            font-size: 14px;
            font-weight: bold;
            margin-top: 4px;
        }
        .solde-card.ecart .solde-value {
            color: #198754;
        }
        .soldes table.layout td {
            padding: 0 4px;
            width: 25%;
        }
        .soldes table.layout td:first-child {
            padding-left: 0;
        }
        .soldes table.layout td:last-child {
            padding-right: 0;
        }

        /* Section title */
        .section-title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 2px solid #0d6efd;
            color: #212529;
        }

        /* Transactions table */
        .tx-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 10px;
        }
        .tx-table thead tr {
            background-color: #e9ecef;
        }
        .tx-table thead th {
            padding: 6px 8px;
            text-align: left;
            font-size: 10px;
            color: #212529;
            font-weight: bold;
            border-bottom: 2px solid #dee2e6;
        }
        .tx-table thead th.text-end {
            text-align: right;
        }
        .even { background-color: #f9f9f9; }
        .tx-table tbody td {
            padding: 5px 8px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
        }
        .tx-table tbody td.text-end {
            text-align: right;
        }
        .tx-table tfoot tr {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .tx-table tfoot td {
            padding: 5px 8px;
            border-top: 2px solid #dee2e6;
        }
        .tx-table tfoot td.text-end {
            text-align: right;
        }
        .text-danger {
            color: #dc3545;
        }
        .text-success {
            color: #198754;
        }

        /* Footer */
        .footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 1px solid #dee2e6;
        }
        .footer-left {
            font-size: 9px;
            color: #6c757d;
        }
        .footer-right {
            font-size: 9px;
            color: #6c757d;
            text-align: right;
        }
    </style>
</head>
<body>

    {{-- HEADER --}}
    <div class="header">
        <table class="layout">
            <tr>
                <td style="width: 60%;">
                    @if ($logoBase64)
                        <img src="data:{{ $logoMime }};base64,{{ $logoBase64 }}" class="logo" alt="Logo">
                    @endif
                    @if ($association)
                        <div class="association-name">{{ $association->nom }}</div>
                        <div class="association-address">
                            @if ($association->adresse){{ $association->adresse }}<br>@endif
                            @if ($association->code_postal || $association->ville){{ $association->code_postal }} {{ $association->ville }}<br>@endif
                            @if ($association->email){{ $association->email }}@endif
                        </div>
                    @endif
                </td>
                <td style="width: 40%;">
                    <div class="doc-title">RAPPROCHEMENT BANCAIRE</div>
                    <div class="doc-date">Généré le {{ \Carbon\Carbon::now()->format('d/m/Y') }}</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- INFO BLOCK --}}
    <div class="info-block">
        <table class="layout">
            <tr>
                <td>
                    <span class="info-label">Compte</span>
                    <span class="info-value">{{ $compte->nom }}</span>
                </td>
                <td>
                    <span class="info-label">Date relevé</span>
                    <span class="info-value">{{ $rapprochement->date_fin->format('d/m/Y') }}</span>
                </td>
                <td>
                    <span class="info-label">Statut</span>
                    <span class="info-value">{{ $rapprochement->statut->label() }}</span>
                </td>
                <td>
                    <span class="info-label">Saisi par</span>
                    <span class="info-value">{{ $rapprochement->saisiPar?->nom ?? '—' }}</span>
                </td>
            </tr>
        </table>
    </div>

    {{-- SOLDES --}}
    <div class="soldes">
        <table class="layout">
            <tr>
                <td>
                    <div class="solde-card">
                        <div class="solde-label">Solde ouverture</div>
                        <div class="solde-value">{{ number_format((float) $rapprochement->solde_ouverture, 2, ',', ' ') }} €</div>
                    </div>
                </td>
                <td>
                    <div class="solde-card">
                        <div class="solde-label">Solde relevé</div>
                        <div class="solde-value">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</div>
                    </div>
                </td>
                <td>
                    @php
                        $soldePointe = (float) $rapprochement->solde_ouverture + $totalCredit - $totalDebit;
                    @endphp
                    <div class="solde-card">
                        <div class="solde-label">Solde pointé</div>
                        <div class="solde-value">{{ number_format($soldePointe, 2, ',', ' ') }} €</div>
                    </div>
                </td>
                <td>
                    @php
                        $ecart = (float) $rapprochement->solde_fin - $soldePointe;
                    @endphp
                    <div class="solde-card ecart">
                        <div class="solde-label">Écart</div>
                        <div class="solde-value">{{ number_format($ecart, 2, ',', ' ') }} €</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- TRANSACTIONS --}}
    <div class="section-title">Transactions pointées ({{ $transactions->count() }})</div>

    @if ($transactions->isEmpty())
        <p style="color: #6c757d; font-style: italic; margin-bottom: 16px;">Aucune transaction pointée.</p>
    @else
        <table class="tx-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 9%;">Date</th>
                    <th style="width: 11%;">Type</th>
                    <th>Libellé</th>
                    <th>Tiers</th>
                    <th style="width: 10%;">Réf.</th>
                    <th class="text-end" style="width: 10%;">Débit</th>
                    <th class="text-end" style="width: 10%;">Crédit</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $i => $tx)
                    <tr class="{{ $i % 2 === 1 ? 'even' : '' }}">
                        <td>{{ $tx['id'] }}</td>
                        <td>{{ \Carbon\Carbon::parse($tx['date'])->format('d/m/Y') }}</td>
                        <td>{{ $tx['type'] }}</td>
                        <td>{{ $tx['label'] }}</td>
                        <td>{{ $tx['tiers'] ?? '—' }}</td>
                        <td>{{ $tx['reference'] }}</td>
                        <td class="text-end text-danger">
                            @if ($tx['montant_signe'] < 0)
                                {{ number_format(abs($tx['montant_signe']), 2, ',', ' ') }} €
                            @endif
                        </td>
                        <td class="text-end text-success">
                            @if ($tx['montant_signe'] > 0)
                                {{ number_format($tx['montant_signe'], 2, ',', ' ') }} €
                            @endif
                        </td>
                    </tr>
                @empty
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6">Total</td>
                    <td class="text-end text-danger">{{ number_format($totalDebit, 2, ',', ' ') }} €</td>
                    <td class="text-end text-success">{{ number_format($totalCredit, 2, ',', ' ') }} €</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- FOOTER --}}
    <div class="footer">
        <table class="layout">
            <tr>
                <td class="footer-left">SVS Accounting — Document généré automatiquement</td>
                <td class="footer-right">Page 1</td>
            </tr>
        </table>
    </div>

</body>
</html>
