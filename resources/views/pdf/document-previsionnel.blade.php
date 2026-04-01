<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $document->type->label() }} {{ $document->numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #212529;
            line-height: 1.4;
            margin: 15mm;
        }
        table { width: 100%; border-collapse: collapse; }
        table.layout td { vertical-align: top; padding: 0; }

        .header { margin-bottom: 18px; }
        .header .logo { max-height: 60px; max-width: 120px; }
        .association-name { font-size: 14px; font-weight: bold; margin-bottom: 2px; }
        .association-subtitle { font-size: 10px; color: #6c757d; margin-bottom: 2px; }
        .association-address { font-size: 10px; color: #6c757d; }

        .doc-title { font-size: 22px; font-weight: bold; color: #0d6efd; }
        .doc-info { font-size: 11px; margin-top: 4px; }
        .doc-info span { display: block; margin-bottom: 2px; }

        .client-block {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }
        .client-label {
            font-size: 9px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-weight: bold;
        }
        .client-name { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
        .client-address { font-size: 10px; color: #555; }

        .lines-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 10px;
        }
        .lines-table thead tr { background-color: #e9ecef; }
        .lines-table thead th {
            padding: 6px 8px;
            text-align: left;
            font-size: 10px;
            color: #212529;
            font-weight: bold;
            border-bottom: 2px solid #dee2e6;
        }
        .lines-table thead th.text-end { text-align: right; }
        .lines-table tbody td {
            padding: 5px 8px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
        }
        .lines-table tbody td.text-end { text-align: right; }
        .lines-table .row-even { background-color: #f9f9f9; }
        .lines-table .ligne-texte td {
            font-weight: bold;
            color: #333;
            padding-top: 8px;
        }
        .lines-table tfoot tr {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .lines-table tfoot td {
            padding: 6px 8px;
            border-top: 2px solid #dee2e6;
        }
        .lines-table tfoot td.text-end { text-align: right; }

        .footer-section {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #dee2e6;
            font-size: 9px;
            color: #6c757d;
        }
        .footer-section p { margin-bottom: 4px; }
    </style>
</head>
<body>

    {{-- HEADER --}}
    <div class="header">
        <table class="layout">
            <tr>
                <td style="width: 60%;">
                    @if ($headerLogoBase64)
                        <img src="data:{{ $headerLogoMime }};base64,{{ $headerLogoBase64 }}" class="logo" alt="Logo">
                    @endif
                    @if ($association)
                        <div class="association-name">{{ $association->nom }}</div>
                        @if ($association->forme_juridique)
                            <div class="association-subtitle">{{ $association->forme_juridique }}</div>
                        @endif
                        <div class="association-address">
                            @if ($association->adresse){{ $association->adresse }}<br>@endif
                            @if ($association->code_postal || $association->ville){{ $association->code_postal }} {{ $association->ville }}<br>@endif
                            @if ($association->email){{ $association->email }}@endif
                            @if ($association->email && $association->telephone) &mdash; @endif
                            @if ($association->telephone){{ $association->telephone }}@endif
                        </div>
                        @if ($association->siret)
                            <div class="association-address" style="margin-top: 2px;">SIRET : {{ $association->siret }}</div>
                        @endif
                    @endif
                </td>
                <td style="width: 40%; text-align: right;">
                    <div class="doc-title">{{ mb_strtoupper($document->type->label()) }}</div>
                    <div class="doc-info">
                        <span><strong>N&deg; :</strong> {{ $document->numero }}</span>
                        <span><strong>Date :</strong> {{ $document->date->format('d/m/Y') }}</span>
                        @if ($document->version > 1)
                            <span><strong>Version :</strong> {{ $document->version }}</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- DESTINATAIRE --}}
    <div class="client-block">
        <div class="client-label">Destinataire</div>
        <div class="client-name">{{ $tiers->displayName() }}</div>
        <div class="client-address">
            @if ($tiers->adresse_ligne1){{ $tiers->adresse_ligne1 }}<br>@endif
            @if ($tiers->code_postal || $tiers->ville){{ $tiers->code_postal }} {{ $tiers->ville }}@endif
        </div>
    </div>

    {{-- LINES TABLE --}}
    @php $montantIndex = 0; @endphp
    <table class="lines-table">
        <thead>
            <tr>
                <th style="width: 75%;">D&eacute;signation</th>
                <th class="text-end" style="width: 25%;">Montant (&euro;)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->lignes_json as $ligne)
                @if ($ligne['type'] === 'texte')
                    <tr class="ligne-texte">
                        <td colspan="2">{{ $ligne['libelle'] }}</td>
                    </tr>
                @else
                    <tr class="{{ $montantIndex % 2 === 1 ? 'row-even' : '' }}">
                        <td>{{ $ligne['libelle'] }}</td>
                        <td class="text-end">{{ number_format((float) $ligne['montant'], 2, ',', "\u{00A0}") }} &euro;</td>
                    </tr>
                    @php $montantIndex++; @endphp
                @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="text-end">{{ number_format((float) $document->montant_total, 2, ',', "\u{00A0}") }} &euro;</td>
            </tr>
        </tfoot>
    </table>

    {{-- FOOTER --}}
    <div class="footer-section">
        <p>Ce document n'est pas une facture.</p>
    </div>

</body>
</html>
