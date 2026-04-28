<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Devis {{ $devis->numero ?? 'Brouillon' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #212529;
            line-height: 1.4;
            margin: 15mm 15mm 25mm 15mm;
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
        .client-contact { font-size: 10px; color: #555; font-style: italic; margin-bottom: 2px; }
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
        .lines-table tfoot tr {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .lines-table tfoot td {
            padding: 6px 8px;
            border-top: 2px solid #dee2e6;
        }
        .lines-table tfoot td.text-end { text-align: right; }

        .mentions-section {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #dee2e6;
            font-size: 9px;
            color: #6c757d;
        }
        .mentions-section p { margin-bottom: 4px; }

        .watermark {
            position: fixed;
            top: 40%;
            left: 10%;
            width: 80%;
            text-align: center;
            font-size: 8rem;
            font-weight: bold;
            color: #cc0000;
            opacity: 0.15;
            transform: rotate(-30deg);
            z-index: 1000;
        }
    </style>
</head>
<body>

    {{-- FILIGRANE BROUILLON --}}
    @if(! empty($brouillonWatermark))
        <div class="watermark">BROUILLON</div>
    @endif

    {{-- HEADER --}}
    <div class="header">
        <table class="layout">
            <tr>
                <td style="width: 60%;">
                    @if(! empty($headerLogoBase64))
                        <img src="data:{{ $headerLogoMime ?? 'image/png' }};base64,{{ $headerLogoBase64 }}" class="logo" alt="Logo">
                    @endif
                    @if($association)
                        <div class="association-name">{{ $association->nom }}</div>
                        @if($association->forme_juridique)
                            <div class="association-subtitle">{{ $association->forme_juridique }}</div>
                        @endif
                        <div class="association-address">
                            @if($association->adresse){{ $association->adresse }}<br>@endif
                            @if($association->code_postal || $association->ville){{ $association->code_postal }} {{ $association->ville }}<br>@endif
                            @if($association->email){{ $association->email }}@endif
                            @if($association->email && $association->telephone) &mdash; @endif
                            @if($association->telephone){{ $association->telephone }}@endif
                        </div>
                        @if($association->siret)
                            <div class="association-address" style="margin-top: 2px;">SIRET&nbsp;: {{ $association->siret }}</div>
                        @endif
                    @endif
                </td>
                <td style="width: 40%; text-align: right;">
                    <div class="doc-title">DEVIS</div>
                    <div class="doc-info">
                        @if($devis->numero)
                            <span><strong>R&eacute;f&eacute;rence&nbsp;:</strong> {{ $devis->numero }}</span>
                        @endif
                        <span><strong>Date d&apos;&eacute;mission&nbsp;:</strong> {{ $devis->date_emission->format('d/m/Y') }}</span>
                        @if($devis->date_validite)
                            <span><strong>Valable jusqu&apos;au&nbsp;:</strong> {{ $devis->date_validite->format('d/m/Y') }}</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ÉMETTEUR (résumé déjà dans le header, bloc séparé si besoin futur) --}}

    {{-- DESTINATAIRE --}}
    <div class="client-block">
        <div class="client-label">Destinataire</div>
        @if($devis->tiers)
            <div class="client-name">{{ $devis->tiers->displayName() }}</div>
            @if($contact = $devis->tiers->displayContact())
                <div class="client-contact">Contact&nbsp;: {{ $contact }}</div>
            @endif
            <div class="client-address">
                @if($devis->tiers->adresse_ligne1){{ $devis->tiers->adresse_ligne1 }}<br>@endif
                @if($devis->tiers->code_postal || $devis->tiers->ville){{ $devis->tiers->code_postal }} {{ $devis->tiers->ville }}@endif
            </div>
        @endif
    </div>

    {{-- OBJET --}}
    @if($devis->libelle)
        <div style="margin-bottom: 12px; font-size: 11px;">
            <strong>Objet&nbsp;:</strong> {{ $devis->libelle }}
        </div>
    @endif

    {{-- TABLEAU DES LIGNES --}}
    <table class="lines-table">
        <thead>
            <tr>
                <th style="width: 50%;">D&eacute;signation</th>
                <th class="text-end" style="width: 17%;">P.U. (&euro;)</th>
                <th class="text-end" style="width: 13%;">Qté</th>
                <th class="text-end" style="width: 20%;">Montant (&euro;)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lignes as $index => $ligne)
                <tr class="{{ $index % 2 === 1 ? 'row-even' : '' }}">
                    <td>{{ $ligne->libelle }}</td>
                    @if($ligne->type === \App\Enums\TypeLigneDevis::Texte)
                        <td class="text-end"></td>
                        <td class="text-end"></td>
                        <td class="text-end"></td>
                    @else
                        <td class="text-end">{{ number_format((float) $ligne->prix_unitaire, 2, ',', "\u{00A0}") }}</td>
                        <td class="text-end">{{ number_format((float) $ligne->quantite, ($ligne->quantite == floor($ligne->quantite) ? 0 : 2), ',', "\u{00A0}") }}</td>
                        <td class="text-end">{{ number_format((float) $ligne->montant, 2, ',', "\u{00A0}") }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total</td>
                <td class="text-end">{{ number_format((float) $devis->montant_total, 2, ',', "\u{00A0}") }} &euro;</td>
            </tr>
        </tfoot>
    </table>

    {{-- MENTIONS --}}
    @php
        $mentions = $association?->facture_mentions_legales ?? null;
    @endphp
    @if($mentions)
        <div class="mentions-section">
            <p>{{ $mentions }}</p>
        </div>
    @endif

    {{-- FOOTER LOGOS --}}
    @include('pdf.partials.footer-logos')

</body>
</html>
