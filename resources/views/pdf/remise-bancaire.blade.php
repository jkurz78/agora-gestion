<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bordereau de remise en banque n°{{ $remise->numero }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            margin: 15mm 15mm 25mm 15mm;
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

        /* Summary */
        .summary {
            margin-top: 16px;
            margin-bottom: 24px;
        }
        .summary table {
            width: auto;
        }
        .summary td {
            padding: 4px 12px 4px 0;
        }
        .summary .label {
            font-weight: bold;
        }

        /* Signature zone */
        .signature-zone {
            margin-top: 30px;
            page-break-inside: avoid;
        }
        .signature-zone .sig-title {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 6px;
        }
        .signature-box {
            border: 1px solid #dee2e6;
            border-radius: 4px;
            height: 80px;
            width: 250px;
        }

    </style>
</head>
<body>
    @include('pdf.partials.footer-logos')

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
                    <div class="doc-title">BORDEREAU DE REMISE EN BANQUE</div>
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
                    <span class="info-label">Date</span>
                    <span class="info-value">{{ $remise->date->format('d/m/Y') }}</span>
                </td>
                <td>
                    <span class="info-label">Banque cible</span>
                    <span class="info-value">{{ $compteCible->nom }}</span>
                </td>
                <td>
                    <span class="info-label">Type</span>
                    <span class="info-value">{{ ucfirst($typeLabel) }}</span>
                </td>
                <td>
                    <span class="info-label">Numéro</span>
                    <span class="info-value">{{ $remise->numero }}</span>
                </td>
            </tr>
        </table>
    </div>

    {{-- TRANSACTIONS --}}
    <div class="section-title">Détail des transactions ({{ $transactions->count() }})</div>

    @if ($transactions->isEmpty())
        <p style="color: #6c757d; font-style: italic; margin-bottom: 16px;">Aucune transaction dans cette remise.</p>
    @else
        <table class="tx-table">
            <thead>
                <tr>
                    <th style="width: 5%;">N°</th>
                    <th style="width: 12%;">Date</th>
                    <th style="width: 12%;">N° pièce</th>
                    <th style="width: 28%;">Tireur</th>
                    <th style="width: 28%;">Libellé</th>
                    <th class="text-end" style="width: 15%;">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($transactions as $i => $tx)
                    <tr class="{{ $i % 2 === 1 ? 'even' : '' }}">
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $tx->date->format('d/m/Y') }}</td>
                        <td>{{ $tx->numero_piece ?? '—' }}</td>
                        <td>{{ $tx->tiers?->displayName() ?? '—' }}</td>
                        <td>{{ $tx->libelle }}</td>
                        <td class="text-end">{{ number_format((float) $tx->montant_total, 2, ',', ' ') }} &euro;</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5">Sous-total — {{ $transactions->count() }} pièce{{ $transactions->count() > 1 ? 's' : '' }}</td>
                    <td class="text-end">{{ number_format((float) $transactions->sum('montant_total'), 2, ',', ' ') }} &euro;</td>
                </tr>
            </tfoot>
        </table>
    @endif

    {{-- TOTAL GÉNÉRAL --}}
    <div class="summary">
        <table>
            <tr>
                <td class="label">Total général — {{ $transactions->count() }} pièce{{ $transactions->count() > 1 ? 's' : '' }}</td>
                <td style="font-weight: bold; font-size: 13px;">{{ number_format($montantTotal, 2, ',', ' ') }} &euro;</td>
            </tr>
        </table>
    </div>

    {{-- SIGNATURE --}}
    <div class="signature-zone">
        <div class="sig-title">Signature</div>
        <div class="signature-box"></div>
    </div>


</body>
</html>
